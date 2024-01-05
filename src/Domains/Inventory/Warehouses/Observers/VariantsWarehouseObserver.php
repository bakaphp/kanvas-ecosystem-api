<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Warehouses\Observers;

use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Actions\CreatePriceHistoryAction;

class VariantsWarehouseObserver
{
    public function saved(VariantsWarehouses $variantWarehouse): void
    {
        if ($variantWarehouse->price) {
            (new CreatePriceHistoryAction(
                $variantWarehouse,
                $variantWarehouse->price
            ))->execute();
        }
    }
}
