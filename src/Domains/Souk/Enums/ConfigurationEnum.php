<?php

declare(strict_types=1);

namespace Kanvas\Souk\Enums;

enum ConfigurationEnum: string
{
    case COMPANY_CUSTOM_CHANNEL_PRICING = 'souk_company_custom_channel_pricing';
    case SEND_NEW_ORDER_NOTIFICATION = 'souk_send_new_order_notification';
    case SEND_NEW_ORDER_TO_OWNER_NOTIFICATION = 'souk_send_new_order_to_owner_notification';
    case USE_B2B_COMPANY_GROUP = 'USE_B2B_COMPANY_GROUP';
    case B2B_GLOBAL_COMPANY = 'B2B_GLOBAL_COMPANY';
    case ALLOW_CROSS_COMPANY_VARIANTS = 'souk_allow_cross_company_variants';
    case EVENT_LARAVEL_CART_ADDED = 'LaravelCart.Added';
    case EVENT_LARAVEL_CART_UPDATED = 'LaravelCart.Updated';
    case CHECK_EXPIRED_ORDERS = 'souk_check_expired_orders';
    case ALLOW_NO_PAYMENT_ORDER = 'allow_no_payment_order';
    case VALIDATE_METADATA_DUPLICATED_ENABLED = 'validate_metadata_duplicated_enabled';
    case CANCEL_STALE_PAYMENTS = 'souk_cancel_stale_payments';
    case STALE_PAYMENT_TTL_MINUTES = 'souk_stale_payment_ttl_minutes';
}
