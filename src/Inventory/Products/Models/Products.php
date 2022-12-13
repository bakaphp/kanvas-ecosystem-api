<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Products\Models;

use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Baka\Traits\UuidTrait;
use Baka\Traits\SlugTrait;
use Kanvas\Inventory\Attributes\Models\Attributes;

/**
 * Class Products
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $products_types_id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property string $description
 * @property ?string $short_description
 * @property ?string $html_description
 * @property ?string $warranty_terms
 * @property ?string $upc
 * @property bool $is_published
 * @property string $published_at
 * @property bool $is_deleted
 */
class Products extends BaseModel
{
    use UuidTrait;
    use SlugTrait;
    protected $table = 'products';
    protected $guarded = [];

    /**
     * categories
     *
     * @return void
     */
    public function categories()
    {
        return $this->belongsToMany(Categories::class, 'products_categories', 'products_id', 'categories_id');
    }

    public function warehouses()
    {
        return $this->belongsToMany(Warehouses::class, 'products_warehouses', 'products_id', 'warehouses_id');
    }

    public function attributes()
    {
        return $this->belongsToMany(Attributes::class, 'products_attributes', 'products_id', 'attributes_id')->withPivot('value');
    }
}
