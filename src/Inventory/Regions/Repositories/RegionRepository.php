<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Regions\Repositories;

use Kanvas\Inventory\Regions\Models\Regions as RegionModel;

class RegionRepository
{
    /**
     * getById
     *
     * @param  int $id
     * @param  ?int $companiesId
     * @return RegionModel
     */
    public static function getById(int $id, ?int $companiesId = null): RegionModel
    {
        $companiesId = $companiesId ?? auth()->user()->default_company;
        return RegionModel::where('companies_id', $companiesId)->where('id', $id)->firstOrFail();
    }
}
