<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Warehouses\Observers;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Throwable;

class WarehouseObserver
{
    public function saving(Warehouses $warehouse): void
    {
        try {
            $defaultWarehouse = Warehouses::where('companies_id', $warehouse->companies_id)
            ->where('is_default', 1)
            ->first();
    
            // if default already exist remove its default
            if ($warehouse->is_default && $defaultWarehouse) {
                $defaultWarehouse->is_default = false;
                $defaultWarehouse->saveOrFail();
            }
    
            if(!$warehouse->is_default && !$defaultWarehouse) {
                throw new ValidationException('Can\'t Save, you have to have at least one default Warehouse');
            }
        } catch (Throwable $e) {
            dd($e);
            // throw new ValidationException('Can\'t Save, you have to have at least one default Warehouse');
        }

    }

}
