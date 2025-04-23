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

    case EVENT_LARAVEL_CART_ADDED = 'LaravelCart.Added';
    case EVENT_LARAVEL_CART_UPDATED = 'LaravelCart.Updated';
}
