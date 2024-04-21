<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Models;

use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Products\Factories\ProductTypeFactory;

/**
 * Class Products.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $name
 * @property string $uuid
 * @property string $slug
 * @property string $description
 * @property int $weight
 * @property string $created_at
 * @property string $updated_at
 * @property bool $is_deleted
 */
class ProductsTypes extends BaseModel
{
    protected $table = 'products_types';
    protected $guarded = [];

    protected static function newFactory()
    {
        return new ProductTypeFactory();
    }
}
