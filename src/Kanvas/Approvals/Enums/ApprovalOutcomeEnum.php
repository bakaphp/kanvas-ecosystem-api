<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Enums;

/**
 * What a decision actually did. Callers must branch on this rather than assume success — recording an
 * approval at a step that has not reached quorum is a valid outcome that runs no handler.
 */
enum ApprovalOutcomeEnum: string
{
    case STILL_PENDING = 'still_pending';
    case ADVANCED = 'advanced';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case DELEGATED = 'delegated';
    case CANCELLED = 'cancelled';
    case ALREADY_RESOLVED = 'already_resolved';
}
