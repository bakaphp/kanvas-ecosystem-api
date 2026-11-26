<?php

declare(strict_types=1);

namespace Kanvas\Connectors\InAppPurchase\Enums;

/**
 * Values of an event's cancel_reason and expiration_reason. CUSTOMER_SUPPORT is a refund.
 */
enum RevenueCatReasonEnum: string
{
    case UNSUBSCRIBE = 'UNSUBSCRIBE';
    case BILLING_ERROR = 'BILLING_ERROR';
    case DEVELOPER_INITIATED = 'DEVELOPER_INITIATED';
    case PRICE_INCREASE = 'PRICE_INCREASE';
    case CUSTOMER_SUPPORT = 'CUSTOMER_SUPPORT';
    case SUBSCRIPTION_PAUSED = 'SUBSCRIPTION_PAUSED';
    case UNKNOWN = 'UNKNOWN';
}
