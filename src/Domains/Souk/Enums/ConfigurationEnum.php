<?php

declare(strict_types=1);

namespace Kanvas\Souk\Enums;

enum ConfigurationEnum: string
{
    case COMPANY_CUSTOM_CHANNEL_PRICING = 'souk_company_custom_channel_pricing';
    case SEND_NEW_ORDER_NOTIFICATION = 'souk_send_new_order_notification';
    case SEND_NEW_ORDER_TO_OWNER_NOTIFICATION = 'souk_send_new_order_to_owner_notification';
}
