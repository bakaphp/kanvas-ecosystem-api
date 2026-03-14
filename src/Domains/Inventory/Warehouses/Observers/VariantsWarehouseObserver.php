<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Warehouses\Observers;

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
            (new CreatePriceHistoryAction(
                $variantWarehouse,
                $variantWarehouse->price
            ))->execute();
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
            (new CreateStatusHistoryAction(
                StatusRepository::getById($variantWarehouse->status_id),
                $variantWarehouse
            ))->execute();
        }

        $productWarehouse = ProductsWarehouses::where('products_id', $variantWarehouse->variant->products_id)
            ->where('warehouses_id', $variantWarehouse->warehouses_id)
            ->withTrashed()
            ->first();

        if (! $productWarehouse) {
            $productWarehouse = new ProductsWarehouses();
            $productWarehouse->products_id = $variantWarehouse->variant->products_id;
            $productWarehouse->warehouses_id = $variantWarehouse->warehouses_id;
            $productWarehouse->is_deleted = 0;
            $productWarehouse->saveOrFail();
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
            $productWarehouse = new ProductsWarehouses();
            $productWarehouse->products_id = $variantWarehouse->variant->products_id;
            $productWarehouse->warehouses_id = $variantWarehouse->warehouses_id;
            $productWarehouse->is_deleted = 0;
            $productWarehouse->saveOrFail();
        }
    }

    public function deleted(VariantsWarehouses $variantWarehouse): void
    {
        $variantWarehouse->warehouse->set(
            'total_products',
            $variantWarehouse->getTotalProducts()
        );

        if ($variantWarehouse->getTotalProducts() === 0) {
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
