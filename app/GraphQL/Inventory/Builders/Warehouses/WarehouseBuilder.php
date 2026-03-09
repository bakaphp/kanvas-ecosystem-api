<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Warehouses;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

class WarehouseBuilder
{
    public function getByChannel(mixed $root, array $args): Builder
    {
        $variantChannelsTable = VariantsChannels::getTableName();
        $warehousesTable = Warehouses::getTableName();

        return Warehouses::query()
            ->join("{$variantChannelsTable} as pvc", "{$warehousesTable}.id", '=', 'pvc.warehouses_id')
            ->where('pvc.channels_id', $args['channel_id'])
            ->select("{$warehousesTable}.*")
            ->distinct();
    }
}
