<?php

declare(strict_types=1);

namespace Kanvas\Souk\Cart\Enums;

enum AbandonCartConfigEnum: string
{
    case ENABLED = 'souk_abandon_cart_enabled';

    // Hours configuration
    case FIRST_HOURS = 'souk_abandon_cart_first_notification_hours';
    case SECOND_HOURS = 'souk_abandon_cart_second_notification_hours';
    case THIRD_HOURS = 'souk_abandon_cart_third_notification_hours';

    // Email templates
    case FIRST_EMAIL_TEMPLATE = 'souk_abandon_cart_first_email_template';
    case SECOND_EMAIL_TEMPLATE = 'souk_abandon_cart_second_email_template';
    case THIRD_EMAIL_TEMPLATE = 'souk_abandon_cart_third_email_template';

    // Push templates
    case FIRST_PUSH_TEMPLATE = 'souk_abandon_cart_first_push_template';
    case SECOND_PUSH_TEMPLATE = 'souk_abandon_cart_second_push_template';
    case THIRD_PUSH_TEMPLATE = 'souk_abandon_cart_third_push_template';

    // Discount codes
    case FIRST_DISCOUNT_CODE = 'souk_abandon_cart_first_discount_code';
    case SECOND_DISCOUNT_CODE = 'souk_abandon_cart_second_discount_code';
    case THIRD_DISCOUNT_CODE = 'souk_abandon_cart_third_discount_code';
}
