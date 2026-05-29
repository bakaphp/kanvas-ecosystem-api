<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\DataTransferObject;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * Per-connector readers MUST yield messages with the role and content already
 * normalized to Laravel AI's MessageRole enum (user / assistant / tool_result);
 * raw 'tool' and 'function' get mapped upstream, 'system' lines are dropped.
 * The base action takes these values as authoritative.
 */
final class ParsedMessage extends Data
{
    public function __construct(
        // Builds the deterministic Kanvas primary key "<session_id>:<runtimeMessageId>"
        // and advances the per-conversation watermark on insert.
        public readonly int|string $runtimeMessageId,
        public readonly string $role,
        // tool_result rows carry content=null per Laravel AI; payload lives in toolResults.
        public readonly ?string $content,
        /** @var array<int, mixed>|null */
        public readonly ?array $toolCalls,
        /** @var array<int, mixed>|null */
        public readonly ?array $toolResults,
        public readonly ?string $toolCallId,
        public readonly ?string $toolName,
        public readonly ?string $finishReason,
        public readonly ?int $tokenCount,
        public readonly ?string $reasoningContent,
        /** @var array<array-key, mixed>|null */
        public readonly ?array $reasoningDetails,
        /** @var array<int, mixed>|null */
        public readonly ?array $codexReasoningItems,
        /** @var array<int, mixed>|null */
        public readonly ?array $codexMessageItems,
        public readonly Carbon $occurredAt,
        // Passthrough for runtime-specific fields the schema mapper doesn't model yet.
        /** @var array<string, mixed> */
        public readonly array $extraMeta = [],
    ) {
    }
}
