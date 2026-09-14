<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\DataTransferObject;

use Kanvas\Connectors\ClaudeAgent\Enums\DrainOutcomeEnum;

/**
 * What one drain of a session's event stream produced.
 *
 * `cursor` is the last event id we consumed — persisted so the next turn resumes from there instead
 * of replaying the whole conversation into the reply.
 */
final class DrainResult
{
    /**
     * @param list<array{id: string, name: string, input: array<string, mixed>}> $pendingToolCalls
     *        Custom tool calls the session is blocked on. Empty unless AWAITING_CLIENT — and it can
     *        also be empty *with* AWAITING_CLIENT, which means the session is waiting on something
     *        we can't satisfy (a tool confirmation, or a tool we never declared).
     * @param array<string, mixed>|null $usage The last `session.usage` payload seen. Cumulative for
     *        the whole session, not this drain — the platform re-reports the running total.
     */
    public function __construct(
        public readonly string $text,
        public readonly DrainOutcomeEnum $outcome,
        public readonly ?string $cursor = null,
        public readonly ?string $stopReason = null,
        public readonly array $pendingToolCalls = [],
        public readonly ?array $usage = null,
    ) {
    }
}
