<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Channels;

use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Regions\Repositories\RegionRepository;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

class ChannelBuilder
{
    public function getByRegion($root, array $args)
    {
        $variantChannelsTable = VariantsChannels::getTableName();
        $warehousesTable = Warehouses::getTableName();
        $channelsTable = Channels::getTableName();

        $region = RegionRepository::getById((int) $args['region_id']);

        return Channels::join("{$variantChannelsTable} as pvc", "{$channelsTable}.id", '=', 'pvc.channels_id')
            ->join("{$warehousesTable} as w", 'pvc.warehouses_id', '=', 'w.id')
            ->where('w.regions_id', $region->getId())
            ->select("{$channelsTable}.*")
            ->distinct();
    }
}
