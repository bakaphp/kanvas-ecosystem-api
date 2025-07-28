<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Enums;

use Baka\Contracts\EnumsInterface;

enum AppEnums implements EnumsInterface
{
    case PRODUCT_VARIANTS_SEARCH_INDEX;
    case PRODUCT_SEARCH_INDEX;
    case CAN_USE_COMMERCE_DISCOUNT_PRICE

    #[Override]
    public function getValue(): mixed
    {
        return match ($this) {
            self::PRODUCT_VARIANTS_SEARCH_INDEX => 'products_variants_company_',
            self::PRODUCT_SEARCH_INDEX => 'products_company_',
            self::CAN_USE_COMMERCE_DISCOUNT_PRICE => 'can_use_commerce_discount_price',
        };
    }
}
