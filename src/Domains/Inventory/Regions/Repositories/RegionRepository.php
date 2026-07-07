<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Regions\Repositories;

use Baka\Contracts\AppInterface;
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

    public static function getDefault(CompanyInterface $company, ?AppInterface $app = null): RegionModel
    {
        // Global default regions (companies_id = 0) are always eligible here, unlike
        // fromCompanyOrGlobal() which gates globals behind the Souk cross-company flag.
        return self::getModel()
            ->where('is_default', 1)
            ->when($app, fn ($query) => $query->fromApp($app))
            ->whereIn('companies_id', [0, $company->getId()])
            ->notDeleted()
            ->orderByRaw('CASE WHEN companies_id = 0 THEN 1 ELSE 0 END ASC')
            ->firstOrFail();
    }
}
