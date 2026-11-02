<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Enums;

use Kanvas\Inventory\Products\Models\Products;

enum InsuranceProductTypeEnum: string
{
    case VEHICLE = 'vehicle';
    case PLAN = 'insurance-plan';

    public function isTypeOf(?Products $product): bool
    {
        return $product?->productsType?->slug === $this->value;
    }
}
