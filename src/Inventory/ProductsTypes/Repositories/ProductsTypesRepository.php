<?php
declare(strict_types=1);

namespace Kanvas\Inventory\ProductsTypes\Repositories;

use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;

class ProductsTypesRepository
{
    /**
     * getById.
     *
     * @param  int $id
     * @param  CompanyInterface|null $company
     *
     * @return Categories
     */
    public static function getById(int $id, ?CompanyInterface $company = null) : ProductsTypes
    {
        $company = $company ?? auth()->user()->getCurrentCompany();
        return ProductsTypes::where('companies_id', $company->getId())
            ->where('apps_id', app(Apps::class)->id)
            ->findOrFail($id);
    }

    /**
     * getBySourceId.
     *
     * @param  mixed $id
     *
     * @return ProductsTypes
     */
    public static function getBySourceKey(string $key, string $id) : ProductsTypes
    {
        $key = $key . '_id';
        return ProductsTypes::getByCustomField($key, $id);
    }
}
