<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Enums;

/**
 * Who can unblock this — which decides whether it interrupts a person.
 *
 * A block that only a human can clear (an approval, a missing decision, information nobody else has)
 * belongs in the conversation where the work was asked for; that is the one a person wants and can act
 * on. A block the ORGANISATION has to clear — a missing tool, an integration nobody connected, a
 * permission — is the PM's to route, and reaches a person as the project digest rather than a ping.
 *
 * Classifying it is the blocking agent's judgment and it will sometimes be wrong. Wrong in the quiet
 * direction is recoverable (the heartbeat still sees a blocked plan); wrong in the loud direction is
 * the noise this exists to avoid.
 */
enum PlanBlockedNeedsEnum: string
{
    /** A person must answer: approval, a decision, information only they have. */
    case HUMAN = 'human';

    /** A tool, integration or permission is missing — an operator problem, not a question. */
    case CAPABILITY = 'capability';

    public function interruptsAPerson(): bool
    {
        return $this === self::HUMAN;
    }
}
