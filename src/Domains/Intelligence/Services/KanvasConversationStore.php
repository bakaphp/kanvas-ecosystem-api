<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;

class KanvasConversationStore implements ConversationStore
{
    public function latestConversationId(string|int $userId): ?string
    {
        [$appsId, $companiesId] = $this->tenantIds();

        return DB::connection('intelligence')->table('agent_conversations')
            ->where('user_id', $userId)
            ->where('apps_id', $appsId)
            ->where('companies_id', $companiesId)
            ->orderBy('updated_at', 'desc')
            ->first()?->id;
    }

    public function storeConversation(string|int|null $userId, string $title): string
    {
        [$appsId, $companiesId] = $this->tenantIds();

        $conversationId = (string) Str::uuid7();

        DB::connection('intelligence')->table('agent_conversations')->insert([
            'id' => $conversationId,
            'user_id' => $userId,
            'apps_id' => $appsId,
            'companies_id' => $companiesId,
            'title' => $title,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $conversationId;
    }

    public function storeUserMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt): string
    {
        $messageId = (string) Str::uuid7();

        DB::connection('intelligence')->table('agent_conversation_messages')->insert([
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
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

        return $messageId;
    }

    public function storeAssistantMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt, AgentResponse $response): string
    {
        $messageId = (string) Str::uuid7();

        DB::connection('intelligence')->table('agent_conversation_messages')->insert([
            'id' => $messageId,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
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

        return $messageId;
    }

    /**
     * @return Collection<int, Message>
     */
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
     */
    public function logTurn(
        int $userId,
        string $sessionId,
        string $agentClass,
        string $userMessage,
        string $assistantResponse,
    ): void {
        $conversationId = $sessionId !== ''
            ? $this->findOrCreateConversationBySession($userId, $sessionId)
            : $this->storeConversation($userId, 'agent-chat-' . now()->timestamp);

        $this->insertMessage($conversationId, $userId, $agentClass, 'user', $userMessage);
        $this->insertMessage($conversationId, $userId, $agentClass, 'assistant', $assistantResponse);
    }

    protected function findOrCreateConversationBySession(int $userId, string $sessionId): string
    {
        [$appsId, $companiesId] = $this->tenantIds();

        $existing = DB::connection('intelligence')->table('agent_conversations')
            ->where('user_id', $userId)
            ->where('apps_id', $appsId)
            ->where('companies_id', $companiesId)
            ->where('title', $sessionId)
            ->first();

        if ($existing) {
            return $existing->id;
        }

        return $this->storeConversation($userId, $sessionId);
    }

    protected function insertMessage(
        string $conversationId,
        int $userId,
        string $agentClass,
        string $role,
        string $content,
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
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
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
