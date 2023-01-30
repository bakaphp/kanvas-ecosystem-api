<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Products\Models;

use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Laravel\Scout\Searchable;

/**
 * Class Products.
 *
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
    use Searchable;

    protected $table = 'products';
    protected $guarded = [];

    /**
     * categories.
     *
     * @return BelongsToMany
     */
    public function categories() : BelongsToMany
    {
        return $this->belongsToMany(
            Categories::class,
            'products_categories',
            'products_id',
            'categories_id'
        );
    }

    /**
     * warehouses.
     *
     * @return BelongsToMany
     */
    public function warehouses() : BelongsToMany
    {
        return $this->belongsToMany(
            Warehouses::class,
            'products_warehouses',
            'products_id',
            'warehouses_id'
        );
    }

    /**
     * attributes.
     *
     * @return BelongsToMany
     */
    public function attributes() : BelongsToMany
    {
        return $this->belongsToMany(
            Attributes::class,
            'products_attributes',
            'products_id',
            'attributes_id'
        )->withPivot('value');
    }

    /**
     * variants.
     *
     * @return HasMany
     */
    public function variants() : HasMany
    {
        return $this->hasMany(Variants::class, 'products_id');
    }

    /**
     * productsTypes.
     *
     * @return BelongsTo
     */
    public function productsTypes() : BelongsTo
    {
        return $this->belongsTo(ProductsTypes::class, 'products_types_id');
    }
}
