<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\InternalServerErrorException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Helpers\ConversationStepsHelper;
use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentConversationMessage;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Override;
use Throwable;

/**
 * Laravel AI's database store on the `intelligence` connection, with the Kanvas columns on top: the
 * acting `user_id`, and the tenant (`apps_id`/`companies_id`) and `agent_id` on the conversation.
 * Steps, status, approval pause / resume, failed turns and replay are all the package's own.
 */
class KanvasConversationStore extends DatabaseConversationStore
{
    private const int JUST_RECORDED_WINDOW_MINUTES = 5;

    public function __construct()
    {
        parent::__construct('intelligence');
    }

    /**
     * Who a conversation belongs to. A human chatting owns it whatever record the session points at — a
     * staff user's session can be keyed to the People record they are working on, and a channel uuid can
     * carry a stale People row from an earlier turn. Only when the acting user is an AI identity does the
     * session's Person own the conversation (public chat, agentic commerce); an AI identity with no Person
     * in scope (a scheduled wake, an @mention, a system-user agent) means the Agent owns it. `user_id` on
     * the rows stays the acting user in every case.
     */
    public static function participantFor(?Session $session, Users $user, ?Agent $agent = null): Model
    {
        if ($agent === null || ! self::actsAsAi($user, $agent)) {
            return $user;
        }

        return $session?->people() ?? $agent;
    }

    /**
     * The agent's dedicated user and the company's shared AI agent user are the two identities an agent
     * runs under; anyone else at the keyboard is a person.
     */
    public static function actsAsAi(Users $user, Agent $agent): bool
    {
        return $user->getId() === (int) $agent->user_id
            || $user->getId() === $agent->company?->getAiAgentUser()?->getId();
    }

    /**
     * No participant means the acting user is the participant — the rule every pre-participant row
     * followed, so old and new writers agree.
     *
     * @return array{0: ?string, 1: ?int}
     */
    public static function participantColumns(?Model $participant, string|int|null $userId): array
    {
        if ($participant !== null) {
            return [$participant->getMorphClass(), (int) $participant->getKey()];
        }

        if ($userId !== null && $userId !== '') {
            return [Relation::getMorphAlias(Users::class), (int) $userId];
        }

        return [null, null];
    }

    /**
     * The package scopes by participant and agent class; a Kanvas user can belong to several apps and
     * companies, so the tenant has to narrow it as well.
     */
    #[Override]
    public function latestConversationId(string $participantType, string|int $participantId, string $agent): ?string
    {
        [$appsId, $companiesId] = $this->tenantFor(
            $participantType === Relation::getMorphAlias(Users::class) ? $participantId : null,
            null,
        );

        return $this->table($this->messagesTable() . ' as m')
            ->join($this->conversationsTable() . ' as c', 'c.id', '=', 'm.conversation_id')
            ->where('m.participant_type', $participantType)
            ->where('m.participant_id', $participantId)
            ->where('m.agent', $agent)
            ->where('c.apps_id', $appsId)
            ->where('c.companies_id', $companiesId)
            ->orderByDesc('m.id')
            ->value('m.conversation_id');
    }

    #[Override]
    public function storeConversation(
        ?string $participantType,
        string|int|null $participantId,
        string $title,
        ?string $id = null
    ): string {
        $agentId = $participantType === Relation::getMorphAlias(Agent::class) ? (int) $participantId : null;
        $userId = $this->actingUserIdFor($participantType, $participantId, $agentId);
        [$appsId, $companiesId] = $this->tenantFor($userId, $agentId);

        return $this->insertConversationRow(
            $userId,
            $agentId,
            $appsId,
            $companiesId,
            $title,
            $participantType,
            $participantId !== null ? (int) $participantId : null,
            $id,
        );
    }

    /**
     * The one lookup that binds a session to its conversation — the agent's own history and logTurn both
     * come through here, so they cannot resolve one chat to two rows. Oldest first, because a session can
     * own several rows and the oldest is the thread a person is reading.
     *
     * A conversation opened for an anonymous public session has no participant; the first turn after the
     * session is keyed to a Person claims it here, so the promotion path never has to know about this table.
     */
    public function conversationForSession(
        int $userId,
        string $sessionId,
        ?int $agentId,
        int $appsId,
        int $companiesId,
        ?Model $participant = null,
    ): string {
        $query = $this->table($this->conversationsTable())
            ->where('user_id', $userId)
            ->where('apps_id', $appsId)
            ->where('companies_id', $companiesId)
            ->where('title', $sessionId);

        $query = $agentId !== null
            ? $query->where('agent_id', $agentId)
            : $query->whereNull('agent_id');

        $conversation = $query->orderBy('id')->first();

        if ($conversation === null) {
            return $this->insertConversation(
                $userId,
                $agentId,
                $appsId,
                $companiesId,
                $sessionId,
                $participant,
            );
        }

        if ($conversation->participant_type === null && $participant !== null) {
            [$participantType, $participantId] = self::participantColumns($participant, $userId);

            $this->table($this->conversationsTable())
                ->where('id', $conversation->id)
                ->update(['participant_type' => $participantType, 'participant_id' => $participantId]);
        }

        return (string) $conversation->id;
    }

    public function insertConversation(
        string|int|null $userId,
        ?int $agentId,
        int $appsId,
        int $companiesId,
        string $title,
        ?Model $participant = null,
    ): string {
        [$participantType, $participantId] = self::participantColumns($participant, $userId);

        return $this->insertConversationRow(
            $userId,
            $agentId,
            $appsId,
            $companiesId,
            $title,
            $participantType,
            $participantId,
        );
    }

    #[Override]
    public function storeAssistantMessage(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        AgentPrompt $prompt,
        AgentResponse $response,
        ?Throwable $exception = null
    ): ?string {
        $messageId = parent::storeAssistantMessage(
            $conversationId,
            $participantType,
            $participantId,
            $prompt,
            $response,
            $exception,
        );

        $this->backfillConversationAgentId($conversationId, $prompt);

        return $messageId;
    }

    /**
     * The acting user comes from the conversation, which `storeConversation()` already resolved for every
     * participant type.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    #[Override]
    protected function messageAttributes(
        string $messageId,
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        mixed $now,
        array $attributes
    ): array {
        return parent::messageAttributes(
            $messageId,
            $conversationId,
            $participantType,
            $participantId,
            $now,
            [
                ...$attributes,
                'user_id' => $this->conversationUserId($conversationId) ?? $this->actingUserIdFor($participantType, $participantId),
            ],
        );
    }

    /**
     * storeConversation can't receive the agent, but the assistant turn carries $prompt->agent (a
     * KanvasLaravelAgent). Backfill it here so the `agentConversations` query filters by agent on the
     * middleware path too. `whereNull('agent_id')` keeps it a once-per-conversation write.
     */
    protected function backfillConversationAgentId(string $conversationId, AgentPrompt $prompt): void
    {
        $agent = $prompt->agent;
        if (! $agent instanceof KanvasLaravelAgent) {
            return;
        }

        $agentId = $agent->getKanvasAgentId();
        if ($agentId === null) {
            return;
        }

        $this->table($this->conversationsTable())
            ->where('id', $conversationId)
            ->whereNull('agent_id')
            ->update([
                'agent_id' => $agentId,
                'updated_at' => now(),
            ]);
    }

    /**
     * A non-empty $sessionId reuses (or creates) the conversation for that session; empty creates a
     * standalone one. The same sessionId under two different agents must not collide —
     * conversationForSession() scopes by agent_id to enforce that.
     *
     * @param array<int, mixed> $toolCalls
     * @param array<int, mixed> $toolResults
     * @param array<string, mixed> $usage
     */
    public function logTurn(
        int $userId,
        string $sessionId,
        string $agentClass,
        string $userMessage,
        string $assistantResponse,
        ?int $agentId = null,
        array $toolCalls = [],
        array $toolResults = [],
        array $usage = [],
        ?Model $participant = null,
    ): void {
        [$appsId, $companiesId] = $this->tenantFor($userId, $agentId);
        [$participantType, $participantId] = self::participantColumns($participant, $userId);

        $conversationId = $sessionId !== ''
            ? $this->conversationForSession(
                $userId,
                $sessionId,
                $agentId,
                $appsId,
                $companiesId,
                $participant,
            )
            : $this->insertConversation(
                $userId,
                $agentId,
                $appsId,
                $companiesId,
                'agent-chat-' . now()->timestamp,
                $participant,
            );

        $this->insertMessage(
            $conversationId,
            $userId,
            $agentClass,
            'user',
            $userMessage,
            participantType: $participantType,
            participantId: $participantId,
        );
        $this->insertMessage(
            $conversationId,
            $userId,
            $agentClass,
            'assistant',
            $assistantResponse,
            $toolCalls,
            $toolResults,
            $usage,
            $participantType,
            $participantId,
        );
    }

    /**
     * Append a single assistant message to the conversation of an existing session — for out-of-band
     * writers (a background job firing a scheduled reminder) that have no `auth()` user and must NOT
     * create a divergent conversation. Explicit tenant ids (the job's app/company), and the message is
     * stamped with the conversation's own `user_id` and participant so it lands in the exact thread the
     * human is viewing. Returns null when there's no conversation for that session yet (the live chat
     * hasn't created one).
     *
     * @param bool $unlessJustRecorded Set when $content is the reply of an agent turn: the turn usually
     *                                 records itself here already, and appending again shows it twice.
     *                                 Usually, not always — a Laravel agent with memory or a turn run as
     *                                 another user records elsewhere, so the write is skipped only when
     *                                 the reply is actually here, never assumed.
     */
    public function appendAssistantMessageForSession(
        int $appsId,
        int $companiesId,
        string $sessionId,
        string $agentClass,
        string $content,
        ?int $agentId = null,
        ?int $userId = null,
        bool $unlessJustRecorded = false,
    ): ?string {
        $query = $this->table($this->conversationsTable())
            ->where('apps_id', $appsId)
            ->where('companies_id', $companiesId)
            ->where('title', $sessionId)
            ->when($userId !== null, fn (Builder $builder): Builder => $builder->where('user_id', $userId));

        // OLDEST, and it has to stay that way: one session id can own several conversations, and
        // `conversationForSession()` binds to the first by id — so that is where the agent's
        // own turns are, and the only thread a person is reading. The ids are uuid7, so ascending is
        // oldest-first; anything else files a message into a stray beside the live conversation.
        $conversation = ($agentId !== null ? $query->where('agent_id', $agentId) : $query)
            ->orderBy('id')
            ->first();

        if ($conversation === null) {
            return null;
        }

        if ($unlessJustRecorded && $this->endsWithJustRecordedReply((string) $conversation->id, $content)) {
            return null;
        }

        return $this->insertMessage(
            (string) $conversation->id,
            (int) $conversation->user_id,
            $agentClass,
            'assistant',
            $content,
            participantType: $conversation->participant_type,
            participantId: $conversation->participant_id !== null ? (int) $conversation->participant_id : null,
        );
    }

    /**
     * Bounded in time so it only ever matches the turn that just ran: a daily agent task can legitimately
     * produce yesterday's exact text, and matching that would drop today's reply. The turn records itself
     * seconds before its delivery, so minutes is ample.
     *
     * Compared on extracted text because the two recorders differ — the agent's own history stores the raw
     * reply, logTurn stores the extracted one — and the delivery carries extracted text.
     */
    private function endsWithJustRecordedReply(string $conversationId, string $content): bool
    {
        $latest = $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->first(['role', 'content', 'created_at']);

        return $latest !== null
            && $latest->role === 'assistant'
            && ChatHelper::extractTextFromResponse((string) $latest->content) === ChatHelper::extractTextFromResponse($content)
            && Carbon::parse($latest->created_at)->gte(now()->subMinutes(self::JUST_RECORDED_WINDOW_MINUTES));
    }

    /**
     * @param array<int, mixed> $toolCalls
     * @param array<int, mixed> $toolResults
     * @param array<string, mixed> $usage
     */
    protected function insertMessage(
        string $conversationId,
        int $userId,
        string $agentClass,
        string $role,
        string $content,
        array $toolCalls = [],
        array $toolResults = [],
        array $usage = [],
        ?string $participantType = null,
        ?int $participantId = null,
    ): string {
        $messageId = (string) Str::uuid7();

        if ($participantType === null) {
            [$participantType, $participantId] = self::participantColumns(null, $userId);
        }

        $this->table($this->messagesTable())->insert([
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'participant_type' => $participantType,
            'participant_id' => $participantId,
            'agent' => $agentClass,
            'role' => $role,
            'content' => $content,
            'attachments' => '[]',
            'steps' => json_encode(ConversationStepsHelper::forRow(
                $role,
                $content,
                $toolCalls,
                $toolResults,
            )),
            'status' => AgentConversationMessage::STATUS_COMPLETED,
            'sequence' => $this->nextSequence($conversationId),
            'usage' => json_encode($usage),
            'meta' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $messageId;
    }

    /**
     * The active window is ordered by this, so every writer of a row stamps one: a reply appended by a
     * scheduled action lands after the chat it joins, not ahead of it. Rows older than the column carry
     * null and sort first.
     */
    public function nextSequence(string $conversationId): int
    {
        return (int) $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->max('sequence') + 1;
    }

    protected function insertConversationRow(
        string|int|null $userId,
        ?int $agentId,
        int $appsId,
        int $companiesId,
        string $title,
        ?string $participantType,
        ?int $participantId,
        ?string $id = null,
    ): string {
        $conversationId = $id ?? (string) Str::uuid7();

        $this->table($this->conversationsTable())->insert([
            'id' => $conversationId,
            'user_id' => $userId,
            'participant_type' => $participantType,
            'participant_id' => $participantId,
            'agent_id' => $agentId,
            'apps_id' => $appsId,
            'companies_id' => $companiesId,
            'title' => $title,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $conversationId;
    }

    private function conversationUserId(string $conversationId): ?int
    {
        $userId = $this->table($this->conversationsTable())->where('id', $conversationId)->value('user_id');

        return $userId !== null ? (int) $userId : null;
    }

    /**
     * The participant Laravel AI hands over is whatever `forUser()` was given — a Users row on every
     * current caller, so its id is the acting user. An Agent participant acts through its dedicated user;
     * a People participant has no user of its own, and the row keeps null until a Kanvas caller, which
     * knows the agent, records the turn.
     */
    private function actingUserIdFor(?string $participantType, string|int|null $participantId, ?int $agentId = null): string|int|null
    {
        if ($participantType === Relation::getMorphAlias(People::class)) {
            return null;
        }

        if ($agentId !== null) {
            return Agent::query()->whereKey($agentId)->value('user_id');
        }

        return $participantId;
    }

    /**
     * The tenant a conversation belongs to, from the agent and the person rather than from auth(): a queue
     * worker has no logged-in user, so auth() would file its turns under company 0, where the lookup misses
     * the real conversation and opens a second one.
     *
     * @return array{0: int, 1: int}
     */
    protected function tenantFor(string|int|null $userId, ?int $agentId): array
    {
        $user = $userId !== null && $userId !== '' ? Users::query()->whereKey((int) $userId)->first() : null;
        $agent = $agentId !== null ? Agent::query()->whereKey($agentId)->first() : null;

        if ($agent !== null) {
            $company = $user !== null ? $agent->companyFor($user) : $agent->company;

            return [
                (int) ($agent->app?->getId() ?? app(Apps::class)->getId()),
                (int) ($company?->getId() ?? 0),
            ];
        }

        if ($user === null) {
            return $this->tenantIds();
        }

        try {
            return [(int) app(Apps::class)->getId(), (int) $user->getCurrentCompany()->getId()];
        } catch (InternalServerErrorException) {
            // A person with no default company on this app: the request context is all that is left.
            return $this->tenantIds();
        }
    }

    /**
     * @return array{int, int}
     */
    protected function tenantIds(): array
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $appsId = $app->getId();
        $companiesId = $user?->getCurrentCompany()?->getId() ?? 0;

        return [$appsId, $companiesId];
    }
}
