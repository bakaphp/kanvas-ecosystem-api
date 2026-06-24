<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Enums;

/**
 *   SENT       → message persisted + dispatched outbound
 *   SKIPPED    → non-terminal gate failed; re-evaluated next tick
 *   EXHAUSTED  → terminal stop; lead won't be tried again until reset
 *   COMPLETED  → terminal stage reached or pipeline closed
 *   ERROR      → unexpected failure during execution
 */
enum FollowUpOutcomeKindEnum: string
{
    case SENT = 'sent';
    case SKIPPED = 'skipped';
    case EXHAUSTED = 'exhausted';
    case COMPLETED = 'completed';
    case ERROR = 'error';
}
