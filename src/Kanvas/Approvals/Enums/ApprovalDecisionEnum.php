<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Enums;

/**
 * One approver's position on a request. WAITING and PENDING are both "no decision yet" — the
 * difference is whether their step is live, which is what stops a step-2 approver signing off before
 * step 1 has cleared.
 */
enum ApprovalDecisionEnum: string
{
    case WAITING = 'waiting';
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case DELEGATED = 'delegated';
    case SKIPPED = 'skipped';
    /** A rule closed this step, not a person — kept distinct so an audit can separate the two. */
    case AUTO_APPROVED = 'auto_approved';

    public function isDecided(): bool
    {
        return match ($this) {
            self::WAITING, self::PENDING => false,
            default => true,
        };
    }

    public function countsTowardQuorum(): bool
    {
        return $this === self::APPROVED || $this === self::AUTO_APPROVED;
    }
}
