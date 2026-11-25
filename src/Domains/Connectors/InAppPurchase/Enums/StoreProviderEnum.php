<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Enums;

/**
 * Stored as apps_stripe_customers.provider, as the subscription type prefix and as the order's source.
 */
enum StoreProviderEnum: string
{
    case APPLE = 'apple';
    case GOOGLE_PLAY = 'google_play';
}
