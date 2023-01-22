<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Products\Repositories;

use Baka\Traits\SearchableTrait;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Inventory\Products\Models\Products;

class ProductsRepository
{
    use SearchableTrait;

    public static function getModel() : Model
    {
        return new Products();
    }

    public static function getBySourceKey(int $id) : Products
    {
    }
}
