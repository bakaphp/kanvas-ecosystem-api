<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Models;

use Awobaz\Compoships\Compoships;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Categories\Models\Categories;
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
    use LikableTrait;
    use Searchable {
        search as public traitSearch;
    }

    use CascadeSoftDeletes;
    use Compoships;

    protected $table = 'products';
    protected $guarded = [];
    protected $cascadeDeletes = ['variants'];

    protected $casts = [
        'is_published' => 'boolean',
    ];

    protected $is_deleted;

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
     * attributes values.
     */
    public function attributeValues(): HasMany
    {
        return $this->hasMany(
            ProductsAttributes::class,
            'products_id',
        );
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
     * @todo remove and change companies for company
     */
    public function companies(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    public function shouldBeSearchable(): bool
    {
        return $this->isPublished();
    }

    public function toSearchableArray(): array
    {
        $product = [
            'objectID' => $this->uuid,
            'id' => $this->id,
            'name' => $this->name,
            'files' => $this->files->map(function ($files) {
                return [
                    'uuid' => $files->uuid,
                    'name' => $files->name,
                    'url' => $files->url,
                    'size' => $files->size,
                    'field_name' => $files->field_name,
                    'attributes' => $files->attributes,
                ];
            }),
            'company' => [
                'id' => $this->companies_id,
                'name' => $this->company->name,
            ],
            'user' => [
                'firstname' => $this->company->user->firstname,
                'lastname' => $this->company->user->lastname,
            ],
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'description' => $this->description,
            'short_description' => $this->short_description,
            'attributes' => [],
            'apps_id' => $this->apps_id,
            'is_deleted' => $this->is_deleted,
        ];
        $attributes = $this->attributes()->get();
        foreach ($attributes as $attribute) {
            $product['attributes'][$attribute->name] = $attribute->value;
        }

        return $product;
    }

    public function searchableAs(): string
    {
        return config('scout.prefix') . 'product_index';
    }

    public static function search($query = '', $callback = null)
    {
        $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
        if (! auth()->user()->isAppOwner()) {
            $query->where('company.id', auth()->user()->getCurrentCompany()->getId());
        }

        return $query;
    }

    public function isPublished(): bool
    {
        return $this->is_deleted && $this->is_published;
    }
}
