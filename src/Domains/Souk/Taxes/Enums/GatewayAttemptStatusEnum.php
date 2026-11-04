<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Enums;

enum GatewayAttemptStatusEnum: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case VOIDED = 'voided';
    case FAILED = 'failed';
    case CLEANUP_PENDING = 'cleanup_pending';
    case NEEDS_RECONCILE = 'needs_reconcile';
}
