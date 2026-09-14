<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\PipelinesStages\Actions;

use Baka\Support\Str;
use Exception;
use Illuminate\Support\Facades\Blade;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead as ModelsLead;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Enums\AgentEnum;
use Kanvas\Intelligence\FollowUp\Models\FollowUpLog;
use Kanvas\Intelligence\Sessions\Actions\CreateContentSessionAction;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Tools\CompanyIsHolidayTool;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;
use Kanvas\Intelligence\Tools\VehicleInterestTool;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Social\Messages\Actions\CreateMessageAction as CreateSocialMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\FailoverableException;
use Laravel\Ai\Responses\StructuredAgentResponse;

use function Laravel\Ai\agent;

/**
 * @deprecated v1 follow-up engine builds + persists outbound messages inside
 *             FollowUpLeadAction directly (Kanvas\Intelligence\FollowUp\Actions\).
 *             Slated for deletion — see docs/intelligence/follow-up-deprecation-spec.md.
 */
class CreateMessageFollowUpAction
{
    protected Agent $agent;
    protected ?Message $createdMessage = null;

    private const int MAX_RETRY_ATTEMPTS = 3;

    private const string PRIMARY_MODEL = 'gemini-2.5-pro';

    /**
     * Fallback legs in preference order. The chain must cross providers: laravel/ai keys the list
     * by provider, so two Gemini entries collapse into one and a "try a sibling model" leg is not
     * expressible. Each entry names its own model because the `model:` argument is ignored as soon
     * as `provider` is an array.
     */
    private const array FALLBACK_MODELS = [
        Lab::OpenAI->value => 'gpt-4o',
        Lab::Anthropic->value => 'claude-sonnet-4',
    ];

    public function __construct(
        protected ModelsLead $lead,
        protected PipelineStage $pipelineStage,
        protected Session $session,
        protected string $messageTemplate,
        protected float $day,
        protected string $communicationChannel = 'sms',
        protected bool $onlyPrompt = false,
        protected ?FollowUpLog $log = null
    ) {
        $this->agent = Agent::fromApp($lead->app)
            ->fromCompany($lead->company)
            ->where('name', AgentEnum::FOLLOW_UP_ENGAGER->value)
            ->firstOrFail();
    }

    public function execute(): ?string
    {
        if ($this->log) {
            $this->log->update([
                'entered_create_message_action' => true,
            ]);
        }

        $text = $this->generateMessageText();

        if ($text === null || $this->onlyPrompt) {
            return $text;
        }

        $this->persistMessage($text);

        return $text;
    }

    /**
     * Generate the follow-up copy from the agent WITHOUT persisting it, so the caller can
     * dedupe (see isDuplicate) before deciding whether to send. Returns null when the template
     * is missing or the agent declines to respond; returns the raw prompt when onlyPrompt is set.
     */
    public function generateMessageText(): ?string
    {
        if ($this->messageTemplate === null) {
            return null;
        }

        $prompt = $this->buildPrompt();

        if ($this->onlyPrompt) {
            return $prompt;
        }

        $responseText = $this->generateResponseWithRetry($prompt);
        $shouldRespond = (bool) ($responseText['should_respond'] ?? false);

        if ($this->log) {
            $this->log->update([
                'should_respond' => $shouldRespond,
                'metadata' => array_merge(
                    $this->log->metadata ?? [],
                    [
                        'ai_response' => [
                            'should_respond' => $shouldRespond,
                            'has_message' => isset($responseText['message']),
                        ],
                    ]
                ),
            ]);
        }

        if ($shouldRespond === false) {
            return null;
        }

        return $responseText['message'];
    }

    public function persistMessage(string $message): Message
    {
        $messageType = MessageTypeService::getOrCreate(
            $this->session->app,
            $this->getMessageTypeVerb()
        );

        $user = $this->lead->company->getAiAgentUser() ?? Users::getById($this->session->agent->user_id);

        $messageInput = MessageInput::from([
            'app' => $this->session->app,
            'company' => $this->session->company,
            'user' => $user,
            'type' => $messageType,
            'message' => [
                'content' => $message,
                'raw_data' => $message,
                'message_id' => '--',
                'chat_jid' => '--',
                'from_me' => true,
            ],
            'is_public' => 1,
        ]);

        $created = new CreateSocialMessageAction(
            $messageInput,
            SystemModulesRepository::getByModelName(
                get_class($this->lead),
                $this->lead->app
            ),
            $this->lead->getId(),
        )->execute();
        $this->createdMessage = $created;

        $this->session->channel->addMessage($created);
        $created->addTag('followup');

        if ($this->log) {
            $this->log->update([
                'message_created' => true,
                'messages_id' => $created->getId(),
            ]);
        }

        return $created;
    }

    /**
     * True when the candidate copy is (near) identical to a follow-up we already sent on this
     * channel. Guards the "same message over and over" case: rather than resend, the caller
     * advances the pipeline stage so the next day-stage template is used. A 90% similarity
     * threshold catches greeting-only variations ("Good morning..." vs "Good afternoon...").
     */
    public function isDuplicate(string $candidate): bool
    {
        $normalizedCandidate = $this->normalizeForCompare($candidate);

        if ($normalizedCandidate === '') {
            return false;
        }

        $recent = $this->session->channel->messages()
            ->where('messages.is_deleted', 0)
            ->orderBy('messages.created_at', 'DESC')
            ->limit(15)
            ->get();

        foreach ($recent as $priorMessage) {
            $payload = $priorMessage->message;

            if (! is_array($payload) || ($payload['from_me'] ?? false) !== true) {
                continue;
            }

            $normalizedPrior = $this->normalizeForCompare((string) ($payload['content'] ?? $payload['raw_data'] ?? ''));

            if ($normalizedPrior === '') {
                continue;
            }

            if ($normalizedPrior === $normalizedCandidate) {
                return true;
            }

            similar_text($normalizedCandidate, $normalizedPrior, $percent);

            if ($percent >= 90.0) {
                return true;
            }
        }

        return false;
    }

    private function normalizeForCompare(string $text): string
    {
        return strtolower(trim((string) preg_replace('/\s+/', ' ', $text)));
    }

    public function getCreatedMessage(): ?Message
    {
        return $this->createdMessage;
    }

    public function buildPrompt(): string
    {
        $companyWorkHour = new CompanyWorkHoursTool($this->lead)->execute();
        $vehicleInterest = new VehicleInterestTool($this->lead)->execute();
        $contentSession = new CreateContentSessionAction($this->session);

        $relatedVehicles = $contentSession->getRelatedVehicles($vehicleInterest, 3);
        $relatedUuid = collect($relatedVehicles)->pluck('uuid')->toArray();

        if (isset($vehicleInterest['uuid'])) {
            $relatedUuid[] = $vehicleInterest['uuid'];
        }

        $channel = Channels::getDefault($this->lead->company);

        $engagementDto = Engagement::from(
            app: $this->lead->app,
            company: $this->lead->company,
            user: $this->lead->company->user,
            lead: $this->lead,
            request: [
                'action' => 'view-vehicle',
                'request_id' => (string) Str::uuid(),
                'source' => 'ai',
                'status' => ActionStatusEnum::SENT->value,
                'data' => [
                    'product_id' => $relatedUuid,
                    'channel_id' => $channel->uuid,
                ],
            ],
            people: $this->lead->people,
        );

        $engagement = new CreateEngagementAction($engagementDto, false)->execute();

        $data = [
            'templates' => $this->messageTemplate,
            'conversation_history' => $this->mapConversationHistory(),
            'context' => [
                'company' => $this->lead->company,
                'lead' => $this->lead,
                'lead_owner' => $this->lead->owner,
            ],
            'work_hours_status' => $companyWorkHour,
            'is_engagement' => $this->lead->get(ConfigurationEnum::IS_ENGAGEMENT->value) ? 1 : 0,
            'holiday_status' => new CompanyIsHolidayTool($this->lead)->execute(),
            'agent' => $this->session->agent,
            'vehicle_interest' => $vehicleInterest,
            'shareMyVehicle' => $engagement->message->message['action_link'] ?? null,
            'day' => $this->day,
        ];

        return Blade::render(implode(' ', $this->agent->role['background']), $data) . $this->buildAntiRepeatDirective();
    }

    /**
     * Appends the follow-ups we already sent plus an explicit "do not repeat" instruction to the
     * prompt. The lead may be parked on the same day-stage for a while (a 90-day drip), so the
     * agent must vary each touch instead of re-emitting the template with only the greeting changed.
     * Returns '' for a fresh lead with no prior sends so the base prompt is untouched.
     */
    private function buildAntiRepeatDirective(): string
    {
        $priorFollowUps = $this->session->channel->messages()
            ->where('messages.is_deleted', 0)
            ->orderBy('messages.created_at', 'DESC')
            ->limit(5)
            ->get()
            ->filter(fn (Message $m): bool => is_array($m->message) && ($m->message['from_me'] ?? false) === true)
            ->map(fn (Message $m): string => (string) ($m->message['content'] ?? $m->message['raw_data'] ?? ''))
            ->filter(fn (string $text): bool => trim($text) !== '')
            ->values();

        if ($priorFollowUps->isEmpty()) {
            return '';
        }

        $list = $priorFollowUps
            ->map(fn (string $text, int $i): string => sprintf('%d. %s', $i + 1, $text))
            ->implode("\n");

        return "\n\nIMPORTANT — DO NOT REPEAT YOURSELF. You have already sent these follow-up messages to this lead:\n"
            . $list
            . "\n\nYour new message MUST be meaningfully different from every message above — a different angle, wording, and call to action. A greeting-only variation of a previous message is NOT acceptable.";
    }

    /**
     * @return array<string, string>
     */
    private static function providerFailoverChain(): array
    {
        $chain = [Lab::Gemini->value => self::PRIMARY_MODEL];

        // Only advertise a leg we can actually authenticate against. An unconfigured key throws a
        // credential error that laravel/ai does not treat as failoverable, which would replace a
        // recoverable overload with a hard stop.
        foreach (self::FALLBACK_MODELS as $provider => $model) {
            if (! empty(config("ai.providers.{$provider}.key"))) {
                $chain[$provider] = $model;

                break;
            }
        }

        return $chain;
    }

    private function generateResponseWithRetry(string $prompt): array
    {
        $lastFailover = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRY_ATTEMPTS; $attempt++) {
            try {
                /** @var StructuredAgentResponse $response */
                $response = agent(
                    schema: fn ($schema) => [
                        'message' => $schema->string()->description('Message for the lead')->required(),
                        'should_respond' => $schema->boolean()->description('Confirmation if must sent message')->required(),
                    ],
                )->prompt(
                    $prompt,
                    provider: self::providerFailoverChain(),
                    timeout: 220,
                );
            } catch (FailoverableException $e) {
                // Only reachable once every model in the chain is overloaded or rate-limited.
                // Without this catch the throw escaped the loop entirely, so an overload spike
                // burned the lead's touch on a single attempt (Sentry KANVAS-ECOSYSTEM-5FV).
                $lastFailover = $e;

                if ($attempt < self::MAX_RETRY_ATTEMPTS) {
                    sleep(2 ** $attempt);
                }

                continue;
            }

            if (! empty($response->structured)) {
                return $response->structured;
            }
        }

        if ($lastFailover !== null) {
            throw $lastFailover;
        }

        throw new Exception(
            sprintf(
                'Failed to generate message response after %d attempts. No valid response received from AI.',
                self::MAX_RETRY_ATTEMPTS
            )
        );
    }

    protected function getMessageTypeVerb(): string
    {
        return match ($this->communicationChannel) {
            'whatsapp' => 'whatsapp',
            'email' => 'email',
            default => 'twilio-sms',
        };
    }

    public function mapConversationHistory(): array
    {
        $conversationMessages = $this->session->channel->messages()->get()
            ->map(fn (Message $message) => [
                'created_at' => $message->created_at,
                'user' => $message->slug ? 'lead' : 'agent',
                'message' => $message->message,
                'type' => 'conversation',
            ]);

        $agentNotesMessages = $this->lead->notes ? $this->lead->notes->messages()->get()
            ->map(fn (Message $message) => [
                'created_at' => $message->created_at,
                'user' => 'agent',
                'message' => $message->message,
                'type' => 'note',
            ]) : [];

        return $conversationMessages
            ->concat($agentNotesMessages)
            ->sortBy('created_at')
            ->map(fn (array $item): array => [
                'user' => $item['user'],
                'message' => $item['message'],
            ])
            ->values()
            ->toArray();
    }
}
