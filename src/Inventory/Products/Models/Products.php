<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Models;

use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Enums\AppEnums;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Social\Interactions\Traits\LikableTrait;
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
    use LikableTrait;

    protected $table = 'products';
    protected $guarded = [];

    protected $casts = [
        'is_published' => 'boolean',
    ];

    /**
     * categories.
     */
    public function categories(): BelongsToMany
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
     */
    public function warehouses(): BelongsToMany
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
     */
    public function attributes(): BelongsToMany
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
     */
    public function variants(): HasMany
    {
        return $this->hasMany(Variants::class, 'products_id');
    }

    /**
     * productsTypes.
     */
    public function productsTypes(): BelongsTo
    {
        return $this->belongsTo(ProductsTypes::class, 'products_types_id');
    }

    /**
     * Get the companies that owns the product.
     */
    public function companies(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

     /**
      * Get the name of the index associated with the model.
      */
      public function searchableAs(): string
      {
          return config('scout.prefix') . AppEnums::PRODUCT_VARIANTS_SEARCH_INDEX->getValue() . $this->apps_id . $this->companies_id;
      }

      public function shouldBeSearchable(): bool
      {
          return $this->isPublished();
      }

      public function isPublished(): bool
      {
          return $this->is_published;
      }
}
