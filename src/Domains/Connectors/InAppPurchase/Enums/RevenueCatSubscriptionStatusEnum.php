<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Enums;

/**
 * `status` of an API v2 subscription. RevenueCat decides access by `gives_access`, not by this.
 */
enum RevenueCatSubscriptionStatusEnum: string
{
    case TRIALING = 'trialing';
    case ACTIVE = 'active';
    case EXPIRED = 'expired';
    case IN_GRACE_PERIOD = 'in_grace_period';
    case IN_BILLING_RETRY = 'in_billing_retry';
    case PAUSED = 'paused';
    case UNKNOWN = 'unknown';
    case INCOMPLETE = 'incomplete';
}
