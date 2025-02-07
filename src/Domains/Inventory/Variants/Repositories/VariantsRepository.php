<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Repositories;

use Baka\Traits\SearchableTrait;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

class VariantsRepository
{
    use SearchableTrait;

    public static function getModel(): Variants
    {
        return new Variants();
    }

    public static function getAvailableVariant(ProductsTypes $productType, Warehouses $warehouse): Variants
    {
        $productTypeId = $productType->getId();
        $warehouseId = $warehouse->getId();
        $appId = $productType->apps_id;
        $companyId = $productType->companies_id;

        $variant = Variants::query()
            ->fromApp($productType->app)
            ->fromCompany($productType->company)
            ->where('is_deleted', 0) // Ensure variant is not deleted
            ->whereHas('product', function ($query) use ($productTypeId, $appId, $companyId) {
                $query->where('products_types_id', $productTypeId)
                      ->where('apps_id', $appId)
                      ->where('companies_id', $companyId)
                      ->where('is_deleted', 0)
                      ->where('is_published', 1);
            })
            ->whereHas('variantWarehouses', function ($query) use ($warehouseId) {
                $query->where('warehouses_id', $warehouseId)
                      ->where('is_deleted', 0)
                      ->where('quantity', '>', 0); // Ensure stock is available
            })
            ->first();

        if (! $variant) {
            throw new ModelNotFoundException('No variant available found for product type ' . $productType->name);
        }

        return $variant;
    }
}