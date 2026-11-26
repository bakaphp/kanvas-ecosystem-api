<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Enums;

/**
 * `auto_renewal_status` of an API v2 subscription.
 */
enum RevenueCatAutoRenewalStatusEnum: string
{
    case WILL_RENEW = 'will_renew';
    case WILL_NOT_RENEW = 'will_not_renew';
    case WILL_CHANGE_PRODUCT = 'will_change_product';
    case WILL_PAUSE = 'will_pause';
    case REQUIRES_PRICE_INCREASE_CONSENT = 'requires_price_increase_consent';
    case HAS_ALREADY_RENEWED = 'has_already_renewed';

    public function endsAtPeriodEnd(): bool
    {
        return $this === self::WILL_NOT_RENEW || $this === self::REQUIRES_PRICE_INCREASE_CONSENT;
    }
}
