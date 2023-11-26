<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Warehouses\Repositories;

use Baka\Traits\SearchableTrait;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

class WarehouseRepository
{
    use SearchableTrait;

    public static function getModel(): Warehouses
    {
        return new Warehouses();
    }
}
