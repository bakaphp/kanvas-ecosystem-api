<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Actions;

use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses as VariantsWarehousesDto;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

/**
 * Makes sure a variant has a row in a warehouse, for syncs that stock one product in several
 * locations (NetSuite today, any connector with per-location inventory). An existing row is returned
 * as it is; quantities stay the sync's job. Whether a missing row may be created at all is the
 * caller's decision: use existing() when it may not.
 */
class EnsureVariantInWarehouseAction
{
    public function __construct(
        protected Variants $variant,
        protected Warehouses $warehouse,
    ) {
    }

    public function execute(): VariantsWarehouses
    {
        $existing = self::existing($this->variant, $this->warehouse);

        if ($existing !== null) {
            return $existing;
        }

        // Price and status come from wherever the variant is already stocked: a new warehouse holds
        // the same goods, and a row created at 0.00 would read as free everywhere price is surfaced.
        // The default warehouse wins so a variant stocked in several places seeds the same price.
        $reference = VariantsWarehouses::query()
            ->where('products_variants_id', $this->variant->getId())
            ->notDeleted()
            ->orderByDesc('is_default')
            ->first();

        return new AddToWarehouseAction(
            $this->variant,
            $this->warehouse,
            new VariantsWarehousesDto(
                variant: $this->variant,
                warehouse: $this->warehouse,
                quantity: 0,
                price: (float) ($reference?->price ?? 0),
                sku: (string) $this->variant->sku,
                status_id: $reference?->status_id,
            )
        )->execute();
    }

    public static function existing(Variants $variant, Warehouses $warehouse): ?VariantsWarehouses
    {
        return VariantsWarehouses::query()
            ->where('products_variants_id', $variant->getId())
            ->where('warehouses_id', $warehouse->getId())
            ->first();
    }
}
