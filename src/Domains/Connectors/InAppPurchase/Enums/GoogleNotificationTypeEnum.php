<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Enums;

enum GoogleNotificationTypeEnum: int
{
    case SUBSCRIPTION_RECOVERED = 1;
    case SUBSCRIPTION_RENEWED = 2;
    case SUBSCRIPTION_CANCELED = 3;
    case SUBSCRIPTION_PURCHASED = 4;
    case SUBSCRIPTION_ON_HOLD = 5;
    case SUBSCRIPTION_IN_GRACE_PERIOD = 6;
    case SUBSCRIPTION_RESTARTED = 7;
    case SUBSCRIPTION_PRICE_CHANGE_CONFIRMED = 8;
    case SUBSCRIPTION_DEFERRED = 9;
    case SUBSCRIPTION_PAUSED = 10;
    case SUBSCRIPTION_PAUSE_SCHEDULE_CHANGED = 11;
    case SUBSCRIPTION_REVOKED = 12;
    case SUBSCRIPTION_EXPIRED = 13;
    case SUBSCRIPTION_PENDING_PURCHASE_CANCELED = 20;

    public function toSubscriptionStatus(): string
    {
        return match ($this) {
            self::SUBSCRIPTION_RECOVERED,
            self::SUBSCRIPTION_RENEWED,
            self::SUBSCRIPTION_PURCHASED,
            self::SUBSCRIPTION_RESTARTED,
            self::SUBSCRIPTION_PRICE_CHANGE_CONFIRMED,
            self::SUBSCRIPTION_DEFERRED => 'active',
            self::SUBSCRIPTION_CANCELED,
            self::SUBSCRIPTION_REVOKED,
            self::SUBSCRIPTION_PENDING_PURCHASE_CANCELED => 'canceled',
            self::SUBSCRIPTION_ON_HOLD,
            self::SUBSCRIPTION_IN_GRACE_PERIOD => 'past_due',
            self::SUBSCRIPTION_PAUSED,
            self::SUBSCRIPTION_PAUSE_SCHEDULE_CHANGED => 'paused',
            self::SUBSCRIPTION_EXPIRED => 'expired',
        };
    }
}
