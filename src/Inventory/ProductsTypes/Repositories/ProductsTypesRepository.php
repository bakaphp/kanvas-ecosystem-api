<?php
declare(strict_types=1);
namespace Kanvas\Inventory\ProductsTypes\Repositories;

use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Apps\Models\Apps;

class ProductsTypesRepository
{
    /**
     * getById
     *
     * @param  int $id
     * @param  int $companiesId
     * @return ProductsTypes
     */
    public static function getById(int $id, ?int $companiesId = null): ProductsTypes
    {
        $companiesId = $companiesId ?? auth()->user()->default_company;
        return ProductsTypes::where('apps_id', app(Apps::class)->id)
            ->where('companies_id', $companiesId)
            ->findOrFail($id);
    }
    
    /**
     * getBySourceId
     *
     * @param  mixed $id
     * @return ProductsTypes
     */
    public static function getBySourceKey(string $key, string $id): ProductsTypes
    {
        $key = "{{$key}}_id";
        return ProductsTypes::getByCustomField($key, $id);
    }
}
