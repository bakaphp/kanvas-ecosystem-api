<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Regions\Repositories;

use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Regions\Models\Regions as RegionModel;

class RegionRepository
{
    /**
     * getById.
     *
     * @param  int $id
     * @param  CompanyInterface|null $company
     *
     * @return Categories
     */
    public static function getById(int $id, ?CompanyInterface $company = null) : RegionModel
    {
        $company = $company ?? auth()->user()->getCurrentCompany();
        return RegionModel::where('companies_id', $company->getId())
            ->where('apps_id', app(Apps::class)->id)
            ->findOrFail($id);
    }
}
