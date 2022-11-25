<?php
declare(strict_types=1);
namespace Kanvas\Inventory\ProductsTypes\Models;

use Kanvas\Inventory\Models\BaseModel;
use Baka\Traits\UuidTrait;
use Baka\Traits\SlugTrait;
use Kanvas\Inventory\Traits\ScopesTrait;

/**
 * Class ProductsTypes
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $name
 * @property string $uuid
 * @property string $slug
 * @property string $description
 * @property int $weight
 */
class ProductsTypes extends BaseModel
{
    use UuidTrait;
    use SlugTrait;
    use ScopesTrait;

    protected $table = "products_types";

    protected $guarded=[];
}
