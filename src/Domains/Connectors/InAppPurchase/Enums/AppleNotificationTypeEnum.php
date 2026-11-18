<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Enums;

use Imdhemy\AppStore\ServerNotifications\V2DecodedPayload;

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

    /**
     * These change what happens at the next renewal, not the period already paid for, so they never move ends_at.
     */
    public function isPreferenceChange(): bool
    {
        return match ($this) {
            self::DID_CHANGE_RENEWAL_STATUS,
            self::DID_CHANGE_RENEWAL_PREF,
            self::PRICE_INCREASE,
            self::REFUND_DECLINED,
            self::CONSUMPTION_REQUEST => true,
            default => false,
        };
    }

    /**
     * A refunded or revoked transaction keeps its original expiresDate, which would leave access open.
     */
    public function isRevocation(): bool
    {
        return $this === self::REFUND || $this === self::REVOKE;
    }

    /**
     * Null leaves the stored status alone. Only the auto-renew switch changes it among preference changes:
     * a price increase or a declined refund says nothing about the period already paid for.
     */
    public function toSubscriptionStatus(?string $subtype): ?SubscriptionStatusEnum
    {
        return match ($this) {
            self::SUBSCRIBED,
            self::DID_RENEW,
            self::RENEWAL_EXTENDED,
            self::OFFER_REDEEMED => SubscriptionStatusEnum::ACTIVE,
            self::DID_CHANGE_RENEWAL_STATUS => match ($subtype) {
                V2DecodedPayload::SUBTYPE_AUTO_RENEW_ENABLED => SubscriptionStatusEnum::ACTIVE,
                V2DecodedPayload::SUBTYPE_AUTO_RENEW_DISABLED => SubscriptionStatusEnum::CANCELED,
                default => null,
            },
            self::DID_FAIL_TO_RENEW => SubscriptionStatusEnum::PAST_DUE,
            self::EXPIRED,
            self::GRACE_PERIOD_EXPIRED => SubscriptionStatusEnum::EXPIRED,
            self::REVOKE,
            self::REFUND => SubscriptionStatusEnum::CANCELED,
            self::DID_CHANGE_RENEWAL_PREF,
            self::PRICE_INCREASE,
            self::REFUND_DECLINED,
            self::CONSUMPTION_REQUEST,
            self::ONE_TIME_CHARGE,
            self::TEST => null,
        };
    }
}
