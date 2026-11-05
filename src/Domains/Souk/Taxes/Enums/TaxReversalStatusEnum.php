<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Enums;

enum TaxReversalStatusEnum: string
{
    case PENDING = 'pending';
    case REVERSING = 'reversing';
    case REVERSED = 'reversed';
    case REVERSAL_FAILED = 'reversal_failed';
    case NEEDS_RECONCILE = 'needs_reconcile';
}
