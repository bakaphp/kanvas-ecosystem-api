<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Enums;

enum GatewayAttemptStatusEnum: string
{
    case PENDING = 'pending';
    case CREATED = 'created';
    case PENDING_GATEWAY = 'pending_gateway';
    case COMPLETED = 'completed';
    case VOIDED = 'voided';
    case FAILED = 'failed';
    case CLEANUP_PENDING = 'cleanup_pending';
    case NEEDS_RECONCILE = 'needs_reconcile';
    case SUPERSEDED = 'superseded';
}
