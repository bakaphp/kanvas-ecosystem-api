<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Models;

use Baka\Traits\SlugTrait;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Products\Factories\ProductTypeFactory;
use Override;

/**
 * Class Products.
 *
 * @property int    $id
 * @property int    $apps_id
 * @property int    $companies_id
 * @property string $name
 * @property string $uuid
 * @property string $slug
 * @property string $description
 * @property int    $weight
 * @property string $created_at
 * @property string $updated_at
 * @property bool   $is_deleted
 *
 * @deprecated v2 (use ProductsTypes instead)
 */
class ProductsTypes extends BaseModel
{
    use SlugTrait;

    protected $table = 'products_types';
    protected $guarded = [];

    #[Override]
    protected static function newFactory()
    {
        return new ProductTypeFactory();
    }
}
