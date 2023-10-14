<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Warehouses\Services;

use Kanvas\Inventory\Warehouses\Models\Warehouses;

class WarehouseService
{
    /**
     * Get default warehouse.
     */
    public static function getDefault(): ?Warehouses
    {
        return Warehouses::where('companies_id', auth()->user()->getCurrentCompany()->getId())
        ->where('is_default', 1)
        ->first();
    }
}
