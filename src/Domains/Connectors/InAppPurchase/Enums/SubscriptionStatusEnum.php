<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Enums;

/**
 * Values stored in subscriptions.stripe_status for store purchases. Cashier only treats past_due as
 * inactive; every other status keeps access until ends_at.
 */
enum SubscriptionStatusEnum: string
{
    case ACTIVE = 'active';
    case CANCELED = 'canceled';
    case EXPIRED = 'expired';
    case PENDING = 'pending';
    case PAST_DUE = 'past_due';
    case PAUSED = 'paused';
}
