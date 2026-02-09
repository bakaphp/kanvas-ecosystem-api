<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Stats\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Stats\DataTransferObject\CapacityStats;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;

class GetCapacityStatsAction
{
    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected ?string $productTypeSlug = null,
        protected ?array $productIds = null,
        protected ?int $warehouseId = null
    ) {
    }

    public function execute(): CapacityStats
    {
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
            ->where('products.apps_id', $this->app->getId())
            ->where('products.companies_id', $this->company->getId());

        if ($this->productTypeSlug) {
            $query->join(
                'products_types',
                'products_types.id',
                '=',
                'products.products_types_id'
            )
            ->where('products_types.slug', $this->productTypeSlug);
        }

        if ($this->productIds && count($this->productIds) > 0) {
            $query->whereIn('products.id', $this->productIds);
        }

        if ($this->warehouseId) {
            $query->where('products_variants_warehouses.warehouses_id', $this->warehouseId);
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
