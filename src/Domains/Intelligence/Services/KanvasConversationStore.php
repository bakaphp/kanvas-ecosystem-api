<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\InternalServerErrorException;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Users\Models\Users;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Override;
use stdClass;

class KanvasConversationStore implements ConversationStore
{
    private const int JUST_RECORDED_WINDOW_MINUTES = 5;

    // Kanvas conversations are user-scoped: $participantId maps to user_id, $participantType is unused.
    #[Override]
    public function latestConversationId(string $participantType, string|int $participantId): ?string
    {
        [$appsId, $companiesId] = $this->tenantFor($participantId, null);

        return DB::connection('intelligence')->table('agent_conversations')
            ->where('user_id', $participantId)
            ->where('apps_id', $appsId)
            ->where('companies_id', $companiesId)
            ->orderBy('updated_at', 'desc')
            ->first()?->id;
    }

    #[Override]
    public function storeConversation(
        ?string $participantType,
        string|int|null $participantId,
        string $title
    ): string {
        return $this->storeConversationForAgent(
            $participantId,
            null,
            $title
        );
    }

    /**
     * The interface `storeConversation` has no slot for the Kanvas agent id; logTurn callers use this
     * variant to set it so the `agentConversations` query can filter by agent.
     */
    public function storeConversationForAgent(
        string|int|null $userId,
        ?int $agentId,
        string $title
    ): string {
        [$appsId, $companiesId] = $this->tenantFor($userId, $agentId);

        return $this->insertConversation($userId, $agentId, $appsId, $companiesId, $title);
    }

    /**
     * The one lookup that binds a session to its conversation — the agent's own history and logTurn both
     * come through here, so they cannot resolve one chat to two rows. Oldest first, because a session can
     * own several rows and the oldest is the thread a person is reading.
     */
    public function conversationForSession(
        int $userId,
        string $sessionId,
        ?int $agentId,
        int $appsId,
        int $companiesId,
    ): string {
        $query = DB::connection('intelligence')->table('agent_conversations')
            ->where('user_id', $userId)
            ->where('apps_id', $appsId)
            ->where('companies_id', $companiesId)
            ->where('title', $sessionId);

        $query = $agentId !== null
            ? $query->where('agent_id', $agentId)
            : $query->whereNull('agent_id');

        return $query->orderBy('id')->first()?->id
            ?? $this->insertConversation($userId, $agentId, $appsId, $companiesId, $sessionId);
    }

    public function insertConversation(
        string|int|null $userId,
        ?int $agentId,
        int $appsId,
        int $companiesId,
        string $title,
    ): string {
        $conversationId = (string) Str::uuid7();

        DB::connection('intelligence')->table('agent_conversations')->insert([
            'id' => $conversationId,
            'user_id' => $userId,
            'agent_id' => $agentId,
            'apps_id' => $appsId,
            'companies_id' => $companiesId,
            'title' => $title,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $conversationId;
    }

    #[Override]
    public function storeUserMessage(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        AgentPrompt $prompt
    ): string {
        $messageId = (string) Str::uuid7();

        DB::connection('intelligence')->table('agent_conversation_messages')->insert([
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'user_id' => $participantId,
            'agent' => $prompt->agent::class,
            'role' => 'user',
            'content' => $prompt->prompt,
            'attachments' => $prompt->attachments->toJson(),
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->backfillConversationAgentId($conversationId, $prompt);

        return $messageId;
    }

    #[Override]
    public function storeAssistantMessage(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        AgentPrompt $prompt,
        AgentResponse $response
    ): ?string {
        $messageId = (string) Str::uuid7();

        DB::connection('intelligence')->table('agent_conversation_messages')->insert([
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'user_id' => $participantId,
            'agent' => $prompt->agent::class,
            'role' => 'assistant',
            'content' => $response->text,
            'attachments' => '[]',
            'tool_calls' => json_encode($response->toolCalls),
            'tool_results' => json_encode($response->toolResults),
            'usage' => json_encode($response->usage),
            'meta' => json_encode($response->meta),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->backfillConversationAgentId($conversationId, $prompt);

        return $messageId;
    }

    /**
     * No-op: Kanvas handles human approval at the Social message layer (message locking), not via
     * Laravel AI's native pause/resume, so no agent ever pauses here for this to persist.
     *
     * @param array<int, ToolResult> $toolResults
     */
    #[Override]
    public function storeApprovalResults(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        array $toolResults
    ): void {
    }

    /**
     * storeConversation can't receive the agent, but the next storeUserMessage carries $prompt->agent
     * (a KanvasLaravelAgent). Backfill it here so the `agentConversations` query filters by agent on the
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

        DB::connection('intelligence')->table('agent_conversations')
            ->where('id', $conversationId)
            ->whereNull('agent_id')
            ->update([
                'agent_id' => $agentId,
                'updated_at' => now(),
            ]);
    }

    /**
     * @return Collection<int<0, max>, Message>
     */
    #[Override]
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return DB::connection('intelligence')->table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->flatMap(function (stdClass $record): array {
                $toolCalls = collect(json_decode($record->tool_calls, true));
                $toolResults = collect(json_decode($record->tool_results, true));

                if ($record->role === 'user') {
                    return [new Message('user', $record->content)];
                }

                if ($toolCalls->isNotEmpty()) {
                    $messages = [];

                    $messages[] = new AssistantMessage(
                        $record->content ?: '',
                        $toolCalls->map(fn ($toolCall) => new ToolCall(
                            id: $toolCall['id'],
                            name: $toolCall['name'],
                            arguments: $toolCall['arguments'],
                            resultId: $toolCall['result_id'] ?? null,
                        ))
                    );

                    if ($toolResults->isNotEmpty()) {
                        $messages[] = new ToolResultMessage(
                            $toolResults->map(fn ($toolResult) => new ToolResult(
                                id: $toolResult['id'],
                                name: $toolResult['name'],
                                arguments: $toolResult['arguments'],
                                result: $toolResult['result'],
                                resultId: $toolResult['result_id'] ?? null,
                            ))
                        );
                    }

                    return $messages;
                }

                return [new AssistantMessage($record->content)];
            });
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
    ): void {
        [$appsId, $companiesId] = $this->tenantFor($userId, $agentId);

        $conversationId = $sessionId !== ''
            ? $this->conversationForSession($userId, $sessionId, $agentId, $appsId, $companiesId)
            : $this->insertConversation($userId, $agentId, $appsId, $companiesId, 'agent-chat-' . now()->timestamp);

        $this->insertMessage($conversationId, $userId, $agentClass, 'user', $userMessage);
        $this->insertMessage(
            $conversationId,
            $userId,
            $agentClass,
            'assistant',
            $assistantResponse,
            $toolCalls,
            $toolResults,
            $usage,
        );
    }

    /**
     * Append a single assistant message to the conversation of an existing session — for out-of-band
     * writers (a background job firing a scheduled reminder) that have no `auth()` user and must NOT
     * create a divergent conversation. Explicit tenant ids (the job's app/company), and the message is
     * stamped with the conversation's own `user_id` so it lands in the exact thread the human is viewing.
     * Returns null when there's no conversation for that session yet (the live chat hasn't created one).
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
        $query = DB::connection('intelligence')->table('agent_conversations')
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
        $latest = DB::connection('intelligence')->table('agent_conversation_messages')
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
    ): string {
        $messageId = (string) Str::uuid7();

        DB::connection('intelligence')->table('agent_conversation_messages')->insert([
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'agent' => $agentClass,
            'role' => $role,
            'content' => $content,
            'attachments' => '[]',
            'tool_calls' => json_encode($toolCalls),
            'tool_results' => json_encode($toolResults),
            'usage' => json_encode($usage),
            'meta' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $messageId;
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
