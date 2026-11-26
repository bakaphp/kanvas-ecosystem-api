<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Enums;

enum RevenueCatEventTypeEnum: string
{
    case TEST = 'TEST';
    case INITIAL_PURCHASE = 'INITIAL_PURCHASE';
    case RENEWAL = 'RENEWAL';
    case CANCELLATION = 'CANCELLATION';
    case UNCANCELLATION = 'UNCANCELLATION';
    case NON_RENEWING_PURCHASE = 'NON_RENEWING_PURCHASE';
    case SUBSCRIPTION_PAUSED = 'SUBSCRIPTION_PAUSED';
    case EXPIRATION = 'EXPIRATION';
    case BILLING_ISSUE = 'BILLING_ISSUE';
    case PRODUCT_CHANGE = 'PRODUCT_CHANGE';
    case TRANSFER = 'TRANSFER';
    case SUBSCRIPTION_EXTENDED = 'SUBSCRIPTION_EXTENDED';
    case TEMPORARY_ENTITLEMENT_GRANT = 'TEMPORARY_ENTITLEMENT_GRANT';
    case REFUND_REVERSED = 'REFUND_REVERSED';
    case INVOICE_ISSUANCE = 'INVOICE_ISSUANCE';
    case VIRTUAL_CURRENCY_TRANSACTION = 'VIRTUAL_CURRENCY_TRANSACTION';
    case EXPERIMENT_ENROLLMENT = 'EXPERIMENT_ENROLLMENT';

    /**
     * A non-renewing purchase has no expiry, so it can never be stored as a subscription row.
     */
    public function changesSubscription(): bool
    {
        return match ($this) {
            self::INITIAL_PURCHASE,
            self::RENEWAL,
            self::CANCELLATION,
            self::UNCANCELLATION,
            self::SUBSCRIPTION_PAUSED,
            self::EXPIRATION,
            self::BILLING_ISSUE,
            self::PRODUCT_CHANGE,
            self::TRANSFER,
            self::SUBSCRIPTION_EXTENDED,
            self::TEMPORARY_ENTITLEMENT_GRANT,
            self::REFUND_REVERSED => true,
            default => false,
        };
    }
}
