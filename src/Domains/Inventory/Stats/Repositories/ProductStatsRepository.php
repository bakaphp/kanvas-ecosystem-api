<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Stats\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Inventory\Stats\DataTransferObject\CapacityStats;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;

class ProductStatsRepository
{
    public static function getCapacityStats(
        AppInterface $app,
        CompanyInterface $company,
        ?string $productTypeSlug = null,
        ?array $productIds = null,
        ?int $warehouseId = null,
        ?int $companiesId = null
    ): CapacityStats {
        $query = VariantsWarehouses::query()
            ->join(
                'products_variants',
                'products_variants.id',
                '=',
                'products_variants_warehouses.products_variants_id'
            )
            ->join(
                'products',
                'products.id',
                '=',
                'products_variants.products_id'
            )
            ->where('products_variants_warehouses.is_deleted', 0)
            ->where('products_variants.is_deleted', 0)
            ->where('products.is_deleted', 0)
            ->where('products.apps_id', $app->getId())
            ->when(
                $companiesId !== null,
                fn ($q) => $q->where('products.companies_id', $companiesId),
                fn ($q) => $q->where('products.companies_id', $company->getId())
            );

        if ($productTypeSlug) {
            $query->join(
                'products_types',
                'products_types.id',
                '=',
                'products.products_types_id'
            )
            ->where('products_types.slug', $productTypeSlug);
        }

        if ($productIds && count($productIds) > 0) {
            $query->whereIn('products.id', $productIds);
        }

        if ($warehouseId) {
            $query->where('products_variants_warehouses.warehouses_id', $warehouseId);
        }

        $stats = $query->selectRaw('
            COALESCE(SUM(products_variants_warehouses.max_capacity), 0) as max_capacity,
            COALESCE(SUM(products_variants_warehouses.quantity), 0) as available_capacity
        ')->first();

        return CapacityStats::fromAggregation(
            (int) $stats->max_capacity,
            (int) $stats->available_capacity
        );
    }
}
