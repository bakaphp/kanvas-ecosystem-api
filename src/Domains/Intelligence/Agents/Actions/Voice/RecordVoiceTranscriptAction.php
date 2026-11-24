<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Voice;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Bouncer;
use Kanvas\AccessControlList\Enums\RolesEnums;
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
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Social\MessagesTypes\Services\MessageTypeService;
use Kanvas\Users\Models\Users;
use Throwable;

use function Sentry\captureException;

/**
 * Voice data plane: file a finished call's transcript as `voice` Messages, one per turn, on the
 * caller's person and active lead — the histories every channel's agent reads, the same way SMS
 * turns are filed.
 *
 * The app comes in through the constructor and is passed to every lookup and write, so the
 * runtime's app key never decides where the transcript lands. Filing on the lead and person
 * channels looks up a role, which Bouncer filters by its scope, so that runs in this app's scope.
 *
 * Runs after capture, so the caller already has a person; a number with none is skipped, never
 * created here. Each turn's slug is the call's CallSid plus its position, so a re-sent call saves
 * nothing twice. No Message workflow fires: a rule on message CREATED would run once per line.
 * Best-effort: returns a structured result; never throws.
 */
class RecordVoiceTranscriptAction
{
    public const string MESSAGE_VERB = 'voice';
    public const int MAX_TURNS = 300;
    public const int MAX_TURN_CHARS = 2000;

    private const array ROLES = ['customer', 'agent'];

    /**
     * @param list<array{role: string, text: string}> $turns in call order
     */
    public function __construct(
        private readonly AppInterface $app,
        private readonly Agent $agent,
        private readonly string $phone,
        private readonly string $callSid,
        private readonly array $turns,
        private readonly ?string $direction = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        return Bouncer::scope()->onceTo(
            RolesEnums::getScope($this->app),
            fn (): array => $this->recordOrReport(),
        );
    }

    /**
     * Caught here and not around onceTo(): it only puts the previous scope back when its callback
     * returns.
     *
     * @return array<string, mixed>
     */
    private function recordOrReport(): array
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
        // The agent is the tenant: another app's transcript would be filed on the wrong customers.
        if ((int) $this->agent->apps_id !== $this->app->getId()) {
            return ['status' => 'error', 'message' => 'The agent does not belong to this app.'];
        }

        $company = $this->agent->company;
        if ($company === null) {
            return ['status' => 'error', 'message' => 'No company is set for this agent.'];
        }

        $people = VoiceCallerLookupService::find($this->app, $company, $this->phone);
        if ($people === null) {
            return ['status' => 'skipped', 'reason' => 'no_contact', 'saved' => 0];
        }

        $lead = LeadsRepository::getPeopleActiveLead($people);
        $user = $company->getAiAgentUser() ?? $this->agent->user;
        $type = MessageTypeService::getOrCreate($this->app, self::MESSAGE_VERB);
        $turns = $this->speakableTurns();
        $alreadySaved = $this->savedSlugs(array_keys($turns));

        foreach ($turns as $slug => $turn) {
            if (in_array($slug, $alreadySaved, true)) {
                continue;
            }

            $message = $this->createTurnMessage(
                $type,
                $user,
                $company,
                $people,
                $turn,
            );
            $this->file($message, $people, $lead, $user);
        }

        return [
            'status' => 'success',
            'people_id' => $people->getId(),
            'lead_id' => $lead?->getId(),
            'saved' => count($turns) - count($alreadySaved),
            'skipped' => count($alreadySaved),
        ];
    }

    /**
     * The turns worth a message, keyed by slug: a known role and some text, capped in count and
     * length. The position is the turn's place in the call as sent, so a retry gets the same slugs.
     *
     * @return array<string, array{index: int, role: string, text: string}>
     */
    private function speakableTurns(): array
    {
        $turns = [];

        foreach (array_slice($this->turns, 0, self::MAX_TURNS) as $index => $turn) {
            $role = (string) ($turn['role'] ?? '');
            $text = Str::trimToNull(mb_substr((string) ($turn['text'] ?? ''), 0, self::MAX_TURN_CHARS));
            if ($text === null || ! in_array($role, self::ROLES, true)) {
                continue;
            }

            $turns[$this->slugFor($index)] = ['index' => $index, 'role' => $role, 'text' => $text];
        }

        return $turns;
    }

    private function slugFor(int $index): string
    {
        return "voice-{$this->callSid}-{$index}";
    }

    /**
     * @param list<string> $slugs
     *
     * @return list<string>
     */
    private function savedSlugs(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        return Message::query()
            ->fromApp($this->app)
            ->whereIn('slug', $slugs)
            ->pluck('slug')
            ->all();
    }

    /**
     * @param array{index: int, role: string, text: string} $turn
     */
    private function createTurnMessage(
        MessageType $type,
        Users $user,
        Companies $company,
        People $people,
        array $turn
    ): Message {
        $fromAgent = $turn['role'] === 'agent';

        $createMessage = new CreateMessageAction(new MessageInput(
            app: $this->app,
            company: $company,
            user: $user,
            type: $type,
            message: AiChatMessagePayload::from([
                'content' => $turn['text'],
                'from_me' => $fromAgent,
                'from_ia' => $fromAgent,
                'session_id' => $this->callSid,
                'agent_id' => $this->agent->getId(),
                'message_id' => "{$this->callSid}:{$turn['index']}",
                'chat_jid' => $this->phone,
                'raw_data' => ['direction' => $this->direction],
            ])->toArray(),
            is_public: 1,
            slug: $this->slugFor($turn['index']),
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
