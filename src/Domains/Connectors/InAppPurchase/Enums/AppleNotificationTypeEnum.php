<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Enums;

enum AppleNotificationTypeEnum: string
{
    case CONSUMPTION_REQUEST = 'CONSUMPTION_REQUEST';
    case DID_CHANGE_RENEWAL_PREF = 'DID_CHANGE_RENEWAL_PREF';
    case DID_CHANGE_RENEWAL_STATUS = 'DID_CHANGE_RENEWAL_STATUS';
    case DID_FAIL_TO_RENEW = 'DID_FAIL_TO_RENEW';
    case DID_RENEW = 'DID_RENEW';
    case EXPIRED = 'EXPIRED';
    case GRACE_PERIOD_EXPIRED = 'GRACE_PERIOD_EXPIRED';
    case OFFER_REDEEMED = 'OFFER_REDEEMED';
    case PRICE_INCREASE = 'PRICE_INCREASE';
    case REFUND = 'REFUND';
    case REFUND_DECLINED = 'REFUND_DECLINED';
    case RENEWAL_EXTENDED = 'RENEWAL_EXTENDED';
    case REVOKE = 'REVOKE';
    case SUBSCRIBED = 'SUBSCRIBED';
    case ONE_TIME_CHARGE = 'ONE_TIME_CHARGE';
    case TEST = 'TEST';

    public function toSubscriptionStatus(): string
    {
        return match ($this) {
            self::SUBSCRIBED,
            self::DID_RENEW,
            self::RENEWAL_EXTENDED,
            self::OFFER_REDEEMED,
            self::DID_CHANGE_RENEWAL_PREF,
            self::DID_CHANGE_RENEWAL_STATUS,
            self::REFUND_DECLINED => 'active',
            self::DID_FAIL_TO_RENEW => 'past_due',
            self::EXPIRED,
            self::GRACE_PERIOD_EXPIRED => 'expired',
            self::REVOKE,
            self::REFUND => 'canceled',
            default => 'active',
        };
    }
}
