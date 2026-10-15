<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Insurance\Enums\InsuranceProductTypeEnum;
use Kanvas\Inventory\Products\Models\Products;

class InsuranceVehicleProduct
{
    public static function isVehicle(?Products $product): bool
    {
        return $product?->productsType?->slug === InsuranceProductTypeEnum::VEHICLE->value;
    }

    public static function isOwnedBy(?Products $product, AppInterface $app, int $userId): bool
    {
        return $product instanceof Products
            && (int) $product->apps_id === $app->getId()
            && ! (bool) $product->is_deleted
            && (int) $product->users_id === $userId;
    }
}
