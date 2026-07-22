<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Override;

class KanvasConversationStore implements ConversationStore
{
    // laravel/ai v0.10 generalized "user" to a "participant" (type + id). Kanvas conversations are
    // always user-scoped, so we map $participantId onto the existing user_id column and ignore
    // $participantType — the behavior is identical to the pre-0.10 user-only contract.
    #[Override]
    public function latestConversationId(string $participantType, string|int $participantId): ?string
    {
        [$appsId, $companiesId] = $this->tenantIds();

        return DB::connection('intelligence')->table('agent_conversations')
            ->where('user_id', $participantId)
            ->where('apps_id', $appsId)
            ->where('companies_id', $companiesId)
            ->orderBy('updated_at', 'desc')
            ->first()?->id;
    }

    #[Override]
    public function storeConversation(?string $participantType, string|int|null $participantId, string $title): string
    {
        return $this->storeConversationForAgent(
            $participantId,
            null,
            $title
        );
    }

    /**
     * Agent-aware conversation insert. The interface `storeConversation` is
     * called by Laravel AI itself and has no place to receive the Kanvas
     * Agent id; explicit callers (the three Run*ChatAction classes via
     * logTurn) must pass `$agentId` here so the row is filterable from the
     * `agentConversations` query.
     */
    public function storeConversationForAgent(
        string|int|null $userId,
        ?int $agentId,
        string $title
    ): string {
        [$appsId, $companiesId] = $this->tenantIds();

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
     * Persist resolved tool-approval results on a paused turn (laravel/ai v0.10). Kanvas doesn't use
     * Laravel AI's native pause/resume approval flow — human approval is handled at the Social message
     * layer (message locking in BaseAgentChannelReplyAction), so no agent ever pauses here and this is
     * never invoked. Kept as a no-op to satisfy the contract; wire it to a paused-turn table only if
     * Kanvas ever adopts the native approval mechanism.
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
     * Wire a conversation row to its Kanvas Agent the first time a message
     * arrives. Laravel AI's `ConversationStore::storeConversation` interface
     * doesn't take the agent — but the very next call (storeUserMessage)
     * carries `$prompt->agent`, which on every Kanvas flow is a
     * KanvasLaravelAgent with the Kanvas Agent model attached. We close that
     * gap here so the `linkedToAgent` scope + `@eq` filter on the
     * `agentConversations` query work for the middleware path too — not just
     * the explicit logTurn callers. `whereNull('agent_id')` makes this a
     * once-per-conversation write; later messages are no-ops at the DB level.
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
            ->flatMap(function ($record) {
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
     * Log a single user+assistant turn for any agent framework.
     *
     * When $sessionId is non-empty, the turn is appended to an existing
     * conversation identified by that session UUID (or a new one is created
     * scoped to the same session). When empty, a standalone conversation is
     * created for this turn only.
     *
     * $agentId is the Kanvas Agent row id; pass it so the conversation row
     * is filterable from the `agentConversations` GraphQL query by agent.
     * Same sessionId across two different agents must NOT collide — that's
     * what the agent_id scoping in findOrCreateConversationBySession enforces.
     */
    /**
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
        $conversationId = $sessionId !== ''
            ? $this->findOrCreateConversationBySession($userId, $sessionId, $agentId)
            : $this->storeConversationForAgent($userId, $agentId, 'agent-chat-' . now()->timestamp);

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

    protected function findOrCreateConversationBySession(int $userId, string $sessionId, ?int $agentId = null): string
    {
        [$appsId, $companiesId] = $this->tenantIds();

        $query = DB::connection('intelligence')->table('agent_conversations')
            ->where('user_id', $userId)
            ->where('apps_id', $appsId)
            ->where('companies_id', $companiesId)
            ->where('title', $sessionId);

        $query = $agentId !== null
            ? $query->where('agent_id', $agentId)
            : $query->whereNull('agent_id');

        $existing = $query->first();

        if ($existing) {
            return $existing->id;
        }

        return $this->storeConversationForAgent($userId, $agentId, $sessionId);
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
     * Returns [apps_id, companies_id] for the current request context.
     *
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
