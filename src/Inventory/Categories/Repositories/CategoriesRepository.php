<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Categories\Repositories;

use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Apps\Models\Apps;

class CategoriesRepository
{
     
    /**
     * getById
     *
     * @param  int $id
     * @param  ?int $companiesId
     * @return Categories
     */
    public static function getById(int $id, ?int $companiesId = null): Categories
    {
        $companiesId = $companiesId ?? auth()->user()->default_company;
        return Categories::where('companies_id', $companiesId)
            ->where('apps_id', app(Apps::class)->id)
            ->findOrFail($id);
    }
}
