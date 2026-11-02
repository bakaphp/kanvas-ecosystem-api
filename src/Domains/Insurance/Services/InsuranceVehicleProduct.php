<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Insurance\Enums\InsuranceProductTypeEnum;
use Kanvas\Insurance\Exceptions\InsuranceValidationException;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;

class InsuranceVehicleProduct
{
    public static function isVehicle(?Products $product): bool
    {
        return InsuranceProductTypeEnum::VEHICLE->isTypeOf($product);
    }

    public static function variantOwnedBy(int $variantId, AppInterface $app, int $userId): Variants
    {
        $variant = Variants::query()
            ->where('id', $variantId)
            ->where('apps_id', $app->getId())
            ->notDeleted()
            ->first();

        if (! self::isVehicle($variant?->product)) {
            throw new InsuranceValidationException('This vehicle is no longer available');
        }

        if (! self::isOwnedBy($variant->product, $app, $userId)) {
            throw new InsuranceValidationException('The vehicle does not belong to this account');
        }

        return $variant;
    }

    public static function isOwnedBy(?Products $product, AppInterface $app, int $userId): bool
    {
        return $product instanceof Products
            && (int) $product->apps_id === $app->getId()
            && ! (bool) $product->is_deleted
            && (int) $product->users_id === $userId;
    }
}
