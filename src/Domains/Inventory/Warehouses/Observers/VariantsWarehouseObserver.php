<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Warehouses\Observers;

use Illuminate\Database\UniqueConstraintViolationException;
use Kanvas\Inventory\Products\Models\ProductsWarehouses;
use Kanvas\Inventory\Status\Actions\CreateStatusHistoryAction;
use Kanvas\Inventory\Status\Repositories\StatusRepository;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Actions\CreatePriceHistoryAction;

class VariantsWarehouseObserver
{
    public function saved(VariantsWarehouses $variantWarehouse): void
    {
        if ($variantWarehouse->wasChanged('price')) {
            new CreatePriceHistoryAction(
                $variantWarehouse,
                $variantWarehouse->price,
                auth()->user(),
            )->execute();
        }

        if ($variantWarehouse->wasChanged('quantity')) {
            $variantWarehouse->warehouse->set(
                'total_products',
                $variantWarehouse->getTotalProducts()
            );

            $variantWarehouse->variant->set(
                'total_variant_quantity',
                $variantWarehouse->variant->setTotalQuantity()
            );
        }

        if ($variantWarehouse->wasChanged('status_id') && $variantWarehouse->status_id !== null) {
            new CreateStatusHistoryAction(
                StatusRepository::getById($variantWarehouse->status_id),
                $variantWarehouse
            )->execute();
        }

        $variantWarehouse->variant->clearLightHouseCacheJob();

        $productWarehouse = ProductsWarehouses::where('products_id', $variantWarehouse->variant->products_id)
            ->where('warehouses_id', $variantWarehouse->warehouses_id)
            ->withTrashed()
            ->first();

        if (! $productWarehouse) {
            try {
                $productWarehouse = new ProductsWarehouses();
                $productWarehouse->products_id = $variantWarehouse->variant->products_id;
                $productWarehouse->warehouses_id = $variantWarehouse->warehouses_id;
                $productWarehouse->is_deleted = 0;
                $productWarehouse->saveOrFail();
            } catch (UniqueConstraintViolationException) {
                // The paired created()/saved() event (or a concurrent write) already created this link.
                // The lookup missed it (composite-key + is_deleted scope + model cache), but the row
                // exists — which is exactly the state we want, so the duplicate is safe to ignore.
            }
        } elseif ($productWarehouse->is_deleted) {
            $productWarehouse->restore();
        }
    }

    public function created(VariantsWarehouses $variantWarehouse): void
    {
        $variantWarehouse->warehouse->set(
            'total_products',
            $variantWarehouse->getTotalProducts()
        );

        $variantWarehouse->variant->set(
            'total_variant_quantity',
            $variantWarehouse->variant->setTotalQuantity()
        );

        $productWarehouse = ProductsWarehouses::where('products_id', $variantWarehouse->variant->products_id)
            ->where('warehouses_id', $variantWarehouse->warehouses_id)
            ->withTrashed()
            ->first();

        if (! $productWarehouse) {
            try {
                $productWarehouse = new ProductsWarehouses();
                $productWarehouse->products_id = $variantWarehouse->variant->products_id;
                $productWarehouse->warehouses_id = $variantWarehouse->warehouses_id;
                $productWarehouse->is_deleted = 0;
                $productWarehouse->saveOrFail();
            } catch (UniqueConstraintViolationException) {
                // The paired created()/saved() event (or a concurrent write) already created this link.
                // The lookup missed it (composite-key + is_deleted scope + model cache), but the row
                // exists — which is exactly the state we want, so the duplicate is safe to ignore.
            }
        }
    }

    public function deleted(VariantsWarehouses $variantWarehouse): void
    {
        $variantWarehouse->warehouse->set(
            'total_products',
            $variantWarehouse->getTotalProducts()
        );

        $hasOtherVariantsInWarehouse = VariantsWarehouses::where('warehouses_id', $variantWarehouse->warehouses_id)
            ->where('is_deleted', 0)
            ->whereHas(
                'variant',
                fn ($query) => $query->where('products_id', $variantWarehouse->variant->products_id)
            )
            ->where('id', '!=', $variantWarehouse->id)
            ->exists();

        if (! $hasOtherVariantsInWarehouse) {
            $productWarehouse = ProductsWarehouses::where('products_id', $variantWarehouse->variant->products_id)
                ->where('warehouses_id', $variantWarehouse->warehouses_id)
                ->withTrashed()
                ->first();

            if ($productWarehouse) {
                $productWarehouse->delete();
            }
        }
    }
}
