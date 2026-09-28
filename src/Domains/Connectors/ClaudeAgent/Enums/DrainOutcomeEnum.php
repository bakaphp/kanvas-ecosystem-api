<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Enums;

/**
 * Why a drain loop stopped. Distinct from the raw `stop_reason` because two of these
 * (TIMED_OUT, AWAITING_CLIENT) are statements about *our* side of the exchange, not the session's.
 *
 * The distinction that matters: only COMPLETED means the agent finished its turn. Everything else
 * needs different handling, and collapsing them would produce the failure this whole connector is
 * designed to avoid — a plausible-looking reply standing in for work that did not happen.
 */
enum DrainOutcomeEnum: string
{
    /** Agent finished its turn normally (`session.status_idle` with `end_turn`). */
    case COMPLETED = 'completed';

    /** Session is blocked on us — a custom tool result or a tool confirmation. */
    case AWAITING_CLIENT = 'awaiting_client';

    /** Session ended irreversibly. Not necessarily a failure — completion terminates too. */
    case TERMINATED = 'terminated';

    /** Idle with `retries_exhausted` — the platform gave up on the turn. */
    case FAILED = 'failed';

    /**
     * Paused at the session spend cap. NOT terminal and NOT resumable by any event — only a budget
     * change or removal restarts it.
     */
    case BUDGET_REACHED = 'budget_reached';

    /** Our wall-clock deadline elapsed first. The session is still running remotely. */
    case TIMED_OUT = 'timed_out';

    public function isSuccessful(): bool
    {
        return $this === self::COMPLETED;
    }
}
