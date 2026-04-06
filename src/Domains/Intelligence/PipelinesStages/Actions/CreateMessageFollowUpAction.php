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
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

class CreateMessageFollowUpAction
{
    protected Agent $agent;

    private const int MAX_RETRY_ATTEMPTS = 3;

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
        $agentName = 'FollowUpEngagerAgent';
        $this->agent = Agent::fromApp($lead->app)
            ->fromCompany($lead->company)
            ->where('name', $agentName)
            ->firstOrFail();
    }

    public function execute(): ?string
    {
        // Log entry to this action
        if ($this->log) {
            $this->log->update([
                'entered_create_message_action' => true,
            ]);
        }

        if ($this->messageTemplate === null) {
            return null;
        }

        $prompt = $this->buildPrompt();

        if ($this->onlyPrompt) {
            return $prompt;
        }

        $responseText = $this->generateResponseWithRetry($prompt);

        $shouldRespond = (bool) ($responseText['should_respond'] ?? false);

        // Log the should_respond value
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

        //if no response or should not respond
        if ($shouldRespond === false) {
            return null;
        }

        $messageType = MessageTypeService::getOrCreate(
            $this->session->app,
            $this->getMessageTypeVerb()
        );

        $agentUser = $this->lead->company->get('ai-agent-user-id');
        if ($agentUser !== null) {
            $user = Users::getById($agentUser);
        } else {
            $user = Users::getById($this->session->agent->user_id);
        }

        $message = $responseText['message'];

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

        $message = new CreateSocialMessageAction(
            $messageInput,
            SystemModulesRepository::getByModelName(
                get_class($this->lead),
                $this->lead->app
            ),
            $this->lead->getId(),
        )->execute();

        $this->session->channel->addMessage($message);
        $message->addTag('followup');

        // Log message creation
        if ($this->log) {
            $this->log->update([
                'message_created' => true,
                'messages_id' => $message->getId(),
            ]);
        }

        return $responseText['message'];
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

        return Blade::render(implode(' ', $this->agent->role['background']), $data);
    }

    private function generateResponseWithRetry(string $prompt): array
    {
        $schema = new ObjectSchema(
            name: 'follow_up_message',
            description: 'Lead message for follow up',
            properties: [
                new StringSchema(
                    name: 'message',
                    description: ' Message for the lead'
                ),
                new BooleanSchema(
                    name: 'should_respond',
                    description: 'Confirmation if must sent message'
                ),
                ],
            requiredFields: [
                    'message',
                    'should_respond',
                ]
        );

        for ($attempt = 1; $attempt <= self::MAX_RETRY_ATTEMPTS; $attempt++) {
            $response = Prism::structured()
                       ->using(Provider::Gemini, 'gemini-2.5-pro')
                       ->withSchema($schema)
                       ->withPrompt($prompt)
                       ->withMaxTokens(7000)
                       ->withClientOptions([
                           'timeout' => 220,
                           'connect_timeout' => 220,
                           'read_timeout' => 220,
                       ])
                       ->asStructured();
            if (! empty($response->structured)) {
                return $response->structured;
            }
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
