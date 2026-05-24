<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Intelligence\AgentRuntime\Contracts\SessionTranscriptReader;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\ParsedMessage;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\ParsedSessionTranscript;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentConversation;
use Kanvas\Intelligence\Agents\Models\AgentConversationMessage;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

/**
 * Idempotency via deterministic primary keys:
 *  - agent_conversations.id        = <session_id>
 *  - agent_conversation_messages.id = <session_id>:<runtimeMessageId>
 * insertOrIgnore absorbs replays; the watermark in agent_conversations.meta
 * lets the next run skip already-imported rows at the source.
 */
abstract class BaseCollectSessionTranscriptsAction
{
    private const int INSERT_CHUNK = 500;

    public function __construct(
        protected readonly AgentDeployment $deployment,
        protected readonly AppInterface $app,
        protected readonly CompanyInterface $company,
        protected readonly ?Carbon $since = null,
    ) {
    }

    abstract protected function reader(): SessionTranscriptReader;

    public function execute(): int
    {
        $agent = $this->deployment->agent;
        if (! $agent instanceof Agent) {
            // Corrupt deployment row — skip rather than crash the cron for one bad agent.
            return 0;
        }

        $persisted = 0;

        foreach ($this->reader()->read($this->deployment, $this->since) as $transcript) {
            $persisted += $this->persistTranscript($transcript, $agent);
        }

        return $persisted;
    }

    private function persistTranscript(ParsedSessionTranscript $transcript, Agent $agent): int
    {
        $appId = (int) $this->app->getId();
        $companyId = (int) $this->company->getId();

        $conversation = AgentConversation::query()->find($transcript->sessionId);

        $sessionMeta = array_filter([
            'source' => $transcript->source,
            'model' => $transcript->model,
            'system_prompt' => $transcript->systemPrompt,
            'parent_session_id' => $transcript->parentSessionId,
            'started_at' => $transcript->startedAt?->toIso8601String(),
            'ended_at' => $transcript->endedAt?->toIso8601String(),
            'end_reason' => $transcript->endReason,
            'message_count' => $transcript->messageCount,
            'tool_call_count' => $transcript->toolCallCount,
            'input_tokens' => $transcript->inputTokens,
            'output_tokens' => $transcript->outputTokens,
            'cache_read_tokens' => $transcript->cacheReadTokens,
            'cache_write_tokens' => $transcript->cacheWriteTokens,
            'reasoning_tokens' => $transcript->reasoningTokens,
            'estimated_cost_usd' => $transcript->estimatedCostUsd,
            'actual_cost_usd' => $transcript->actualCostUsd,
            'handoff_state' => $transcript->handoffState,
            'runtime_source_reader' => $transcript->sourceReader,
            'runtime' => $this->runtimeName(),
        ], static fn ($v): bool => $v !== null && $v !== '');

        if ($transcript->rawMeta !== []) {
            $sessionMeta['runtime_raw'] = $transcript->rawMeta;
        }

        // Carry the watermark across no-new-rows runs so we don't drop it.
        $existingWatermark = $conversation?->meta['runtime_last_message_id'] ?? null;
        if ($existingWatermark !== null) {
            $sessionMeta['runtime_last_message_id'] = $existingWatermark;
        }

        $title = $transcript->title !== null && $transcript->title !== ''
            ? $transcript->title
            : sprintf('%s:%s', $this->runtimeName(), $transcript->source ?? 'unknown');

        $resolvedUserId = $this->resolveUserId($transcript, $agent);

        if ($conversation === null) {
            AgentConversation::query()->insert([
                'id' => $transcript->sessionId,
                'agent_id' => $agent->getId(),
                'apps_id' => $appId,
                'companies_id' => $companyId,
                'user_id' => $resolvedUserId,
                'title' => Str::limit($title, 250, ''),
                'meta' => json_encode($sessionMeta, JSON_THROW_ON_ERROR),
                'created_at' => $transcript->startedAt ?? now(),
                'updated_at' => now(),
            ]);
            $conversation = AgentConversation::query()->findOrFail($transcript->sessionId);
        } else {
            $updates = [
                'agent_id' => $agent->getId(),
                'title' => Str::limit($title, 250, ''),
                'meta' => array_merge((array) $conversation->meta, $sessionMeta),
                'updated_at' => now(),
            ];
            // Back-fill user_id for rows imported before the persona fallback existed.
            if ($conversation->user_id === null && $resolvedUserId !== null) {
                $updates['user_id'] = $resolvedUserId;
            }
            $conversation->update($updates);
        }

        if ($transcript->messages === []) {
            return 0;
        }

        $persisted = 0;
        $highestRuntimeId = $conversation->meta['runtime_last_message_id'] ?? null;
        $rows = [];
        $agentTag = $agent->uuid;

        foreach ($transcript->messages as $msg) {
            $rows[] = $this->buildMessageRow($transcript, $msg, $agentTag);
            $highestRuntimeId = $this->maxRuntimeId($highestRuntimeId, $msg->runtimeMessageId);

            if (count($rows) >= self::INSERT_CHUNK) {
                $persisted += $this->flushMessageRows($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            $persisted += $this->flushMessageRows($rows);
        }

        if ($highestRuntimeId !== null) {
            $conversation->update([
                'meta' => array_merge((array) $conversation->meta, ['runtime_last_message_id' => $highestRuntimeId]),
            ]);
        }

        return $persisted;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMessageRow(
        ParsedSessionTranscript $transcript,
        ParsedMessage $msg,
        string $agentTag,
    ): array {
        $meta = array_filter([
            'runtime_message_id' => $msg->runtimeMessageId,
            'tool_call_id' => $msg->toolCallId,
            'tool_name' => $msg->toolName,
            'finish_reason' => $msg->finishReason,
            'token_count' => $msg->tokenCount,
            'reasoning_content' => $msg->reasoningContent,
            'reasoning_details' => $msg->reasoningDetails,
            'codex_reasoning_items' => $msg->codexReasoningItems,
            'codex_message_items' => $msg->codexMessageItems,
            'occurred_at' => $msg->occurredAt->toIso8601String(),
            'runtime_source_reader' => $transcript->sourceReader,
        ], static fn ($v): bool => $v !== null && $v !== '');

        if ($msg->extraMeta !== []) {
            $meta['runtime_extra'] = $msg->extraMeta;
        }

        // insertOrIgnore is a query-builder call — it bypasses Eloquent's Baka\Casts\Json
        // cast on the model, so JSON columns must be pre-encoded here. Matching the
        // KanvasConversationStore convention of empty `'[]'` for unused columns.
        return [
            'id' => sprintf('%s:%s', $transcript->sessionId, (string) $msg->runtimeMessageId),
            'conversation_id' => $transcript->sessionId,
            'user_id' => null,
            'agent' => $agentTag,
            'role' => $msg->role,
            'content' => $msg->content,
            'attachments' => '[]',
            'tool_calls' => json_encode($msg->toolCalls ?? [], JSON_THROW_ON_ERROR),
            'tool_results' => json_encode($msg->toolResults ?? [], JSON_THROW_ON_ERROR),
            'usage' => $msg->tokenCount !== null
                ? json_encode(['token_count' => $msg->tokenCount], JSON_THROW_ON_ERROR)
                : '[]',
            'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
            'created_at' => $msg->occurredAt,
            'updated_at' => $msg->occurredAt,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function flushMessageRows(array $rows): int
    {
        return AgentConversationMessage::query()->insertOrIgnore($rows);
    }

    /**
     * Hermes uses int sqlite ids; future runtimes might use string ULIDs.
     * Integer pair → numeric max; anything else → lexicographic.
     */
    private function maxRuntimeId(int|string|null $current, int|string $candidate): int|string
    {
        if ($current === null || $current === '') {
            return $candidate;
        }
        if (is_int($current) && is_int($candidate)) {
            return max($current, $candidate);
        }
        return ((string) $candidate) > ((string) $current) ? $candidate : $current;
    }

    protected function runtimeName(): string
    {
        return strtolower(class_basename(static::class));
    }

    /**
     * Priority: runtime-resolved user (from rawMeta['resolved_user_id'] —
     * future Slack→Users mapping) → agent's persona user (agents.user_id is
     * non-null). Conversation ownership stays on agent_id; user_id is an
     * attribution hint, not a tenant boundary.
     */
    private function resolveUserId(ParsedSessionTranscript $transcript, Agent $agent): ?int
    {
        $runtimeResolved = $transcript->rawMeta['resolved_user_id'] ?? null;
        if (is_int($runtimeResolved) && $runtimeResolved > 0) {
            return $runtimeResolved;
        }
        if (is_string($runtimeResolved) && is_numeric($runtimeResolved) && (int) $runtimeResolved > 0) {
            return (int) $runtimeResolved;
        }

        return $agent->user_id !== null ? (int) $agent->user_id : null;
    }
}
