<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Categories\Repositories;

use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Categories\Models\Categories;

class CategoriesRepository
{
    /**
     * getById.
     *
     * @param  int $id
     * @param  CompanyInterface|null $company
     *
     * @return Categories
     */
    public static function getById(int $id, ?CompanyInterface $company = null) : Categories
    {
        $company = $company ?? auth()->user()->getCurrentCompany();
        return Categories::where('companies_id', $company->getId())
            ->where('apps_id', app(Apps::class)->id)
            ->findOrFail($id);
    }
}
