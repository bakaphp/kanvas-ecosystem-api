<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Regions\Repositories;

use Baka\Contracts\CompanyInterface;
use Baka\Traits\SearchableTrait;
use Kanvas\Inventory\Regions\Models\Regions as RegionModel;

class RegionRepository
{
    use SearchableTrait;

    public static function getModel(): RegionModel
    {
        return new RegionModel();
    }

    public static function getDefault(CompanyInterface $company): RegionModel
    {
        return self::getModel()
            ->where('is_default', 1)
            ->fromCompanyOrGlobal($company)
            ->orderByRaw('CASE WHEN companies_id = 0 THEN 1 ELSE 0 END ASC')
            ->firstOrFail();
    }
}
