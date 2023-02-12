<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Regions\Repositories;

use Baka\Traits\SearchableTrait;
use Kanvas\Inventory\Regions\Models\Regions as RegionModel;

class RegionRepository
{
    use SearchableTrait;

    public static function getModel(): RegionModel
    {
        return new RegionModel();
    }
}
