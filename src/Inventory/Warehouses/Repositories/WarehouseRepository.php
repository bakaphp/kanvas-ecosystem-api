<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Warehouses\Repositories;

use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

class WarehouseRepository
{
    /**
     * getById.
     *
     * @param  int $id
     * @param  CompanyInterface|null $company
     *
     * @return Categories
     */
    public static function getById(int $id, ?CompanyInterface $company = null) : Warehouses
    {
        $company = $company ?? auth()->user()->getCurrentCompany();
        return Warehouses::where('companies_id', $company->getId())
            ->where('apps_id', app(Apps::class)->id)
            ->findOrFail($id);
    }
}
