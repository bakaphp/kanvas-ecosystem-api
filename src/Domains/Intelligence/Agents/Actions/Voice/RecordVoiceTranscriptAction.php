<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Voice;

use Baka\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\PeopleChannelService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Guild\Leads\Services\LeadChannelService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\VoiceCallerLookupService;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\AiChatMessagePayload;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Users\Models\Users;
use Throwable;

use function Sentry\captureException;

/**
 * Voice data plane: file a finished call as ONE `voice` Message on the caller's person and active
 * lead — the histories every channel's agent reads, the same way SMS is filed. Its content is the
 * runtime's summary of the call (what the next agent reads); the turns ride along in the payload's
 * `transcript`, for anyone who needs the whole conversation. Without a summary (an older runtime),
 * the content is the transcript itself as "Customer:/Agent:" lines.
 *
 * Every lookup and write takes the agent's app, so the runtime's app key never decides where the
 * call lands. Filing on the lead and person channels looks up a role under Bouncer's scope,
 * which the mutation sets to that app.
 *
 * Runs after capture, so the caller already has a person; a number with none is skipped, never
 * created here. The slug is the call's CallSid, so a re-sent call saves nothing twice. No Message
 * workflow fires: these are records of a call that already happened, not messages to answer.
 * Best-effort: returns a structured result; never throws.
 */
class RecordVoiceTranscriptAction
{
    public const string MESSAGE_VERB = 'voice';
    public const int MAX_TURNS = 300;
    public const int MAX_TURN_CHARS = 2000;
    public const int MAX_SUMMARY_CHARS = 2000;

    private const array SPEAKERS = ['customer' => 'Customer', 'agent' => 'Agent'];

    /**
     * @param list<array{role: string, text: string}> $turns in call order
     */
    public function __construct(
        private readonly Agent $agent,
        private readonly string $phone,
        private readonly string $callSid,
        private readonly array $turns,
        private readonly ?string $direction = null,
        private readonly ?string $summary = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        try {
            return $this->record();
        } catch (Throwable $e) {
            captureException($e);
            logger()->error('recordVoiceTranscript failed', [
                'phone' => $this->phone,
                'call_sid' => $this->callSid,
                'agent_id' => $this->agent->getId(),
                'exception' => $e->getMessage(),
                'at' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return ['status' => 'error', 'message' => "The call transcript couldn't be saved."];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function record(): array
    {
        $company = $this->agent->company;
        if ($company === null) {
            return ['status' => 'error', 'message' => 'No company is set for this agent.'];
        }

        $turns = $this->speakableTurns();
        $summary = Str::trimToNull(mb_substr((string) $this->summary, 0, self::MAX_SUMMARY_CHARS));
        if ($turns === [] && $summary === null) {
            return ['status' => 'skipped', 'reason' => 'nothing_said', 'saved' => 0];
        }

        $people = VoiceCallerLookupService::find($this->agent->app, $company, $this->phone);
        if ($people === null) {
            return ['status' => 'skipped', 'reason' => 'no_contact', 'saved' => 0];
        }

        $lead = LeadsRepository::getPeopleActiveLead($people);
        $saved = Message::query()
            ->fromApp($this->agent->app)
            ->where('slug', $this->slug())
            ->exists();

        if (! $saved) {
            $user = $company->getAiAgentUser() ?? $this->agent->user;
            $message = $this->createCallMessage(
                $user,
                $company,
                $people,
                $summary ?? $this->transcriptText($turns),
                $turns,
            );
            $this->file($message, $people, $lead, $user);
        }

        return [
            'status' => 'success',
            'people_id' => $people->getId(),
            'lead_id' => $lead?->getId(),
            'saved' => $saved ? 0 : 1,
        ];
    }

    /**
     * The lines worth keeping: a known role and some text, capped in count and length.
     *
     * @return list<array{role: string, text: string}>
     */
    private function speakableTurns(): array
    {
        $turns = [];

        foreach (array_slice($this->turns, 0, self::MAX_TURNS) as $turn) {
            $role = (string) ($turn['role'] ?? '');
            $text = Str::trimToNull(mb_substr((string) ($turn['text'] ?? ''), 0, self::MAX_TURN_CHARS));
            if ($text === null || ! isset(self::SPEAKERS[$role])) {
                continue;
            }

            $turns[] = ['role' => $role, 'text' => $text];
        }

        return $turns;
    }

    /**
     * @param list<array{role: string, text: string}> $turns
     */
    private function transcriptText(array $turns): string
    {
        return implode("\n", array_map(
            fn (array $turn): string => self::SPEAKERS[$turn['role']] . ': ' . $turn['text'],
            $turns,
        ));
    }

    private function slug(): string
    {
        return "voice-{$this->callSid}";
    }

    /**
     * @param list<array{role: string, text: string}> $turns
     */
    private function createCallMessage(
        Users $user,
        Companies $company,
        People $people,
        string $content,
        array $turns,
    ): Message {
        // The agent's record of the call, not something the customer sent: a contact message would
        // count as their last inbound (follow-up silence, the WhatsApp reply window) and as a reply
        // in engagement reports.
        $payload = AiChatMessagePayload::from([
            'content' => $content,
            'from_me' => true,
            'from_ia' => true,
            'session_id' => $this->callSid,
            'agent_id' => $this->agent->getId(),
            'message_id' => $this->callSid,
            'chat_jid' => $this->phone,
            'raw_data' => ['direction' => $this->direction],
        ])->toArray();
        $payload['transcript'] = $turns;

        $createMessage = new CreateMessageAction(new MessageInput(
            app: $this->agent->app,
            company: $company,
            user: $user,
            type: MessageTypeService::getOrCreate($this->agent->app, self::MESSAGE_VERB),
            message: $payload,
            is_public: 1,
            slug: $this->slug(),
            people: $people,
        ));
        $createMessage->runWorkflow = false;

        return $createMessage->execute();
    }

    private function file(Message $message, People $people, ?Lead $lead, Users $user): void
    {
        if ($lead !== null) {
            new LeadChannelService()->recordOnLeadAndPerson($message, $lead, $user);

            return;
        }

        new PeopleChannelService()->recordOnPerson($message, $people, $user);
    }
}
