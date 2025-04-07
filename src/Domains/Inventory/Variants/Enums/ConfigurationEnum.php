<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Enums;

enum ConfigurationEnum: string
{
    case WEIGHT_UNIT = 'GRAMS';
    case PRODUCT_VARIANTS_SEARCH_LIMIT = 'products_variants_search_limit';
}
