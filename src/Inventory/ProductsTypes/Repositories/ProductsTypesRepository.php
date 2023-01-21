<?php
declare(strict_types=1);

namespace Kanvas\Inventory\ProductsTypes\Repositories;

use Baka\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;

class ProductsTypesRepository
{
    use SearchableTrait;

    public static function getModel() : Model
    {
        return new ProductsTypes();
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
