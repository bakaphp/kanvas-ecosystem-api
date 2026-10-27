<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Enums;

enum TaxStatusEnum: string
{
    case CALCULATED = 'calculated';
    case COMMITTING = 'committing';
    case COMMITTED = 'committed';
    case COMMIT_FAILED = 'commit_failed';
    case NEEDS_RECONCILE = 'needs_reconcile';
    case VOIDED = 'voided';
    case SUPERSEDED = 'superseded';
    case PARTIALLY_REVERSED = 'partially_reversed';
    case REVERSED = 'reversed';
    case MISMATCH = 'mismatch';
}
