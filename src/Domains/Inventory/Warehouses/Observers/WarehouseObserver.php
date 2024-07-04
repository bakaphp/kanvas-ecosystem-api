<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Warehouses\Observers;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

class WarehouseObserver
{
    public function creating(Warehouses $warehouse): void
    {
        $defaultWarehouse = $warehouse::getDefault($warehouse->company);

        // if default already exist remove its default
        if ($warehouse->is_default && $defaultWarehouse) {
            $defaultWarehouse->is_default = false;
            $defaultWarehouse->saveQuietly();
        }

        if (! $warehouse->is_default && ! $defaultWarehouse) {
            throw new ValidationException('Can\'t Save, you have to have at least one default Warehouse');
        }
    }

    public function updating(Warehouses $warehouse): void
    {
        $defaultWarehouse = Warehouses::getDefault($warehouse->company);

        // if default already exist remove its default
        if ($defaultWarehouse &&
            $warehouse->is_default &&
            $warehouse->getId() != $defaultWarehouse->getId()
        ) {
            $defaultWarehouse->is_default = false;
            $defaultWarehouse->saveQuietly();
        } elseif ($defaultWarehouse &&
            ! $warehouse->is_default &&
            $warehouse->getId() == $defaultWarehouse->getId()
        ) {
            throw new ValidationException('Can\'t Save, you have to have at least one default Warehouse');
        }
    }

    public function deleting(Warehouses $warehouse): void
    {
        if ($warehouse->hasDependencies()) {
            throw new ValidationException('Can\'t delete, Warehouse has variants associated');
        }

        $defaultWarehouse = $warehouse::getDefault($warehouse->company);

        if ($defaultWarehouse->getId() == $warehouse->getId()) {
            throw new ValidationException('Can\'t delete, you have to have at least one default Warehouse');
        }
    }
}
