<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Enums;

use Baka\Contracts\EnumsInterface;

enum AppEnums implements EnumsInterface
{
    case PRODUCT_VARIANTS_SEARCH_INDEX;

    /**
     * Get value.
     */
    public function getValue(): mixed
    {
        return match ($this) {
            self::PRODUCT_VARIANTS_SEARCH_INDEX => 'products_variants_company_',
        };
    }
}
