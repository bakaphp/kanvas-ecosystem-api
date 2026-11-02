<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

use Baka\Support\Str;
use Kanvas\Insurance\Enums\VehicleInsuranceAttributeEnum;
use Kanvas\Inventory\Products\Models\Products;

class VehicleAttributeReader
{
    public static function read(Products $vehicle, VehicleInsuranceAttributeEnum $attribute): ?string
    {
        $value = $vehicle->getAttributeBySlug(Str::slug($attribute->value))?->value;

        return Str::trimToNull(is_scalar($value) ? (string) $value : null);
    }
}
