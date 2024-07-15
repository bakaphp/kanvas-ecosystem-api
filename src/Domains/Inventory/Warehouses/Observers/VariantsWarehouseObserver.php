<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Warehouses\Observers;

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

        if ($variantWarehouse->wasChanged('status_id')) {
            (new CreateStatusHistoryAction(
                StatusRepository::getById($variantWarehouse->status_id),
                $variantWarehouse
            ))->execute();
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
    }

    public function deleted(VariantsWarehouses $variantWarehouse): void
    {
        $variantWarehouse->warehouse->set(
            'total_products',
            $variantWarehouse->getTotalProducts()
        );
    }
}
