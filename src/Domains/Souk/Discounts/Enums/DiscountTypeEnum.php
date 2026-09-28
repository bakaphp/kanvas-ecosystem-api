<?php

declare(strict_types=1);

namespace Kanvas\Souk\Discounts\Enums;

enum DiscountTypeEnum: string
{
    case FIXED_AMOUNT = 'fixed_amount';
    case PERCENTAGE = 'percentage';
    case FREE_SHIPPING = 'free_shipping';
    case BUY_X_GET_Y = 'buy_x_get_y';
    case AUTO_APPLIED_CREDIT = 'auto_applied_credit';

    public function label(): string
    {
        return match ($this) {
            self::FIXED_AMOUNT => 'Fixed Amount',
            self::PERCENTAGE => 'Percentage',
            self::FREE_SHIPPING => 'Free Shipping',
            self::BUY_X_GET_Y => 'Buy X Get Y',
            self::AUTO_APPLIED_CREDIT => 'Auto Applied Credit',
        };
    }
}
