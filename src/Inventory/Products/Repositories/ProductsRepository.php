<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Products\Repositories;

use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Apps\Models\Apps;

class ProductsRepository
{
    /**
     * getById
     *
     * @param  int $id
     * @param  int $companiesId
     * @return Products
     */
    public static function getById(int $id, ?int $companiesId = null): Products
    {
        $companiesId = $companiesId ?? auth()->user()->default_company;

        return Products::where('companies_id', $companiesId)
            ->where('apps_id', app(Apps::class)->id)
            ->findOrFail($id);
    }
}
