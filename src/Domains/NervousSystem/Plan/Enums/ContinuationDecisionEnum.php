<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Enums;

/**
 * What should happen the next time a plan is woken.
 *
 * Until now this was a sentence of prose in `WakeAgentForPlanJob::buildMessage()` — "close the plan
 * if the work is finished" — which made the loop's control flow a request to the model, re-decided
 * from scratch on every wake with no memory of how many times it had already been asked. These are
 * the five answers that sentence was standing in for, computed from the plan's own rows.
 */
enum ContinuationDecisionEnum: string
{
    /** Open tasks exist and can be worked now. */
    case DISPATCH = 'dispatch';

    /** Every task is finished but the goal is not met — the plan needs more tasks. */
    case EXTEND = 'extend';

    /** Everything is done. Hand to whatever establishes the goal was actually met. */
    case VERIFY = 'verify';

    /** Something is stuck in a way only a human can clear. */
    case BLOCK = 'block';

    /** A budget ran out. Stop, and say why. */
    case ABANDON = 'abandon';

    /** Whether this verdict means the plan keeps running unattended. */
    public function continuesUnattended(): bool
    {
        return match ($this) {
            self::DISPATCH, self::EXTEND, self::VERIFY => true,
            self::BLOCK, self::ABANDON => false,
        };
    }

    /**
     * The instruction the woken agent receives. Written as a direct order because a hedged
     * instruction is how the loop drifted in the first place.
     */
    public function instruction(): string
    {
        return match ($this) {
            self::DISPATCH => 'There are open tasks on this plan. Work the next one. Do NOT close the plan and '
                . 'do NOT add new tasks while unfinished ones remain.',
            self::EXTEND => 'Every task on this plan is finished, but the goal is not met yet. Add the tasks '
                . 'that would finish it. If you believe the goal IS met, say so explicitly and close the plan.',
            self::VERIFY => 'All tasks are complete and the goal appears met. Summarise what was achieved '
                . 'against the original objective, then close the plan.',
            self::BLOCK => 'This plan is blocked and you cannot clear it yourself. Explain what is blocking it '
                . 'and what a human needs to do. Do NOT attempt the blocked work again.',
            self::ABANDON => 'This plan has exhausted its budget and is being stopped. Summarise what was done '
                . 'and what remains, so a human can decide whether to continue it.',
        };
    }
}
