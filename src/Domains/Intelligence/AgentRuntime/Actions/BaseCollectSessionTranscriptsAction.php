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

    // Matches the agent_conversation_messages.id column width (VARCHAR(100)
    // after the 2026_05_24 widen). Anything longer is hashed to a 36-char
    // sha1 prefix so we never silently overflow — see composeMessageId().
    private const int MAX_MESSAGE_ID_LENGTH = 100;

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

        $conversation = AgentConversation::query()
            ->where('id', $transcript->sessionId)
            ->where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->where('agent_id', $agent->getId())
            ->first();

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
            $attributes = [
                'id' => $transcript->sessionId,
                'agent_id' => $agent->getId(),
                'apps_id' => $appId,
                'companies_id' => $companyId,
                'user_id' => $resolvedUserId,
                'title' => Str::limit($title, 250, ''),
                'meta' => json_encode($sessionMeta, JSON_THROW_ON_ERROR),
                'created_at' => $transcript->startedAt ?? now(),
                'updated_at' => now(),
            ];
            AgentConversation::query()->insert($attributes);
            // Hydrate locally rather than re-reading. `findOrFail` would hit
            // the read replica under prod's master/replica topology and throw
            // ModelNotFoundException for any row still propagating from the
            // write just above. `newFromBuilder` binds an Eloquent instance
            // to the same data we just inserted, exists=true, no extra query.
            $conversation = (new AgentConversation())->newFromBuilder($attributes);
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
            'id' => $this->composeMessageId($transcript->sessionId, (string) $msg->runtimeMessageId),
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
     * Compose the deterministic message PK as `<sessionId>:<runtimeMessageId>`,
     * falling back to a stable sha1 of the same input when the composite
     * exceeds MAX_MESSAGE_ID_LENGTH. Stable because sha1 is deterministic,
     * so re-ingesting the same source still produces the same key — which
     * is the property `insertOrIgnore` relies on for idempotency.
     *
     * Hashing only kicks in if a runtime ever emits a runtimeMessageId
     * pathologically long enough to overflow the widened 100-char column.
     * Today's runtimes (Hermes ints, OpenClaw short strings) stay under
     * 50 chars composite, well below the bound.
     */
    private function composeMessageId(string $sessionId, string $runtimeMessageId): string
    {
        $composite = $sessionId . ':' . $runtimeMessageId;
        if (strlen($composite) <= self::MAX_MESSAGE_ID_LENGTH) {
            return $composite;
        }

        // Prefix the readable session id so debugging by `LIKE 'sess:%'`
        // still works; suffix the deterministic hash of the runtime id.
        $hashLength = self::MAX_MESSAGE_ID_LENGTH - strlen($sessionId) - 1;
        if ($hashLength < 8) {
            // sessionId itself is at/near the cap — drop the prefix and
            // return a pure sha1 of the whole composite (capped to width).
            return substr(sha1($composite), 0, self::MAX_MESSAGE_ID_LENGTH);
        }

        return $sessionId . ':' . substr(sha1($runtimeMessageId), 0, $hashLength);
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
