<?php

declare(strict_types=1);

namespace Kanvas\Souk\Discounts\Enums;

enum DiscountConditionTypeEnum: string
{
    case PRODUCT = 'product';
    case CATEGORY = 'category';
    case VARIANT = 'variant';
    case CUSTOMER = 'customer';
    case CUSTOMER_GROUP = 'customer_group';

    public function label(): string
    {
        return match ($this) {
            self::PRODUCT => 'Product',
            self::CATEGORY => 'Category',
            self::VARIANT => 'Variant',
            self::CUSTOMER => 'Customer',
            self::CUSTOMER_GROUP => 'Customer Group',
        };
    }
}
