<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Warehouses\Repositories;

use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Apps\Models\Apps;

class WarehouseRepository
{
    /**
     * getById
     *
     * @param  mixed $id
     * @param  int $companiesId
     * @return Warehouses
     */
    public static function getById(int $id, ?int $companiesId = null): Warehouses
    {
        $companiesId = $companiesId ?? auth()->user()->default_company;
        return Warehouses::where('companies_id', $companiesId)
            ->where('apps_id', app(Apps::class)->id)
            ->findOrFail($id);
    }
}
