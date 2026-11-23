<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Concerns;

use Kanvas\Inventory\Variants\Actions\AddToWarehouseAction;
use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses as VariantsWarehousesDto;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

trait ResolvesVariantWarehouse
{
    /**
     * The row is only created on request. A warehouse mapped to the wrong location would otherwise
     * gain a row for every SKU in the catalog on its first sync, which is not something you can
     * undo by fixing the mapping — hence opt-in rather than default.
     */
    protected function resolveVariantWarehouse(Variants $variant, Warehouses $warehouse, bool $createMissing): ?VariantsWarehouses
    {
        $variantWarehouse = VariantsWarehouses::query()
            ->where('products_variants_id', $variant->getId())
            ->where('warehouses_id', $warehouse->getId())
            ->first();

        if ($variantWarehouse !== null || ! $createMissing) {
            return $variantWarehouse;
        }

        // Price comes from wherever the variant is already stocked: a new warehouse holds the same
        // goods, and a row created at 0.00 would read as free everywhere price is surfaced. The
        // default warehouse wins so a variant stocked in several places seeds the same price twice.
        $reference = VariantsWarehouses::query()
            ->where('products_variants_id', $variant->getId())
            ->notDeleted()
            ->orderByDesc('is_default')
            ->first();

        return new AddToWarehouseAction(
            $variant,
            $warehouse,
            new VariantsWarehousesDto(
                variant: $variant,
                warehouse: $warehouse,
                quantity: 0,
                price: (float) ($reference?->price ?? 0),
                sku: (string) $variant->sku,
                status_id: $reference?->status_id,
            )
        )->execute();
    }
}
