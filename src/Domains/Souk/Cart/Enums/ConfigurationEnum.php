<?php

declare(strict_types=1);

namespace Kanvas\Souk\Cart\Enums;

enum ConfigurationEnum: string
{
    case ABANDON_CART_ENABLED = 'souk_abandon_cart_enabled';
    case ABANDON_CART_FIRST_NOTIFICATION_HOURS = 'souk_abandon_cart_first_notification_hours';
    case ABANDON_CART_SECOND_NOTIFICATION_HOURS = 'souk_abandon_cart_second_notification_hours';
    case ABANDON_CART_THIRD_NOTIFICATION_HOURS = 'souk_abandon_cart_third_notification_hours';
}
