<?php

declare(strict_types=1);

namespace Kanvas\Inventory\ProductsTypes\Models;

use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Products\Factories\ProductTypeFactory;
use Kanvas\Inventory\Products\Models\Products;
use Baka\Traits\DatabaseSearchableTrait;
use Kanvas\Inventory\Traits\ScopesTrait;

/**
 * Class ProductsTypes.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $users_id
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
    use DatabaseSearchableTrait;
    use CascadeSoftDeletes;

    protected $table = 'products_types';
    protected $guarded = [];

    /**
     * Get the user that owns the ProductsTypes.
     */
    public function companies(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Products::class, 'products_types_id');
    }

    public function productsTypesAttributes(): HasMany
    {
        return $this->hasMany(ProductsTypesAttributes::class, 'products_Types_id');
    }

    /**
     * attributes.
     */
    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(
            Attributes::class,
            ProductsTypesAttributes::class,
            'products_types_id',
            'attributes_id'
        );
    }

    /**
     * Get the total amount of products of a product type.
     */
    public function getTotalProducts(): int
    {
        if (! $totalProducts = $this->get('total_products')) {
            return (int) $this->setTotalProducts();
        }

        return (int) $totalProducts;
    }

    /**
     * Set the total amount of products of a product type.
     */
    public function setTotalProducts(): int
    {
        $total = Products::where('products_types_id', $this->getId())
                ->where('is_deleted', 0)
                ->count();

        $this->set('total_products', $total);

        return (int) $total;
    }

    /**
     * Get all the products attributes from the product type
     */
    public function getProductsAttributes(): Collection
    {
        return $this->attributes()
                            ->where('to_variant', 0)
                            ->where('products_types_attributes.is_deleted', 0)
                            ->get();
    }

    /**
     * Get all the variants attributes from the product type
     */
    public function getVariantsAttributes(): Collection
    {
        return $this->attributes()
                            ->where('to_variant', 1)
                            ->where('products_types_attributes.is_deleted', 0)
                            ->get();
    }

    public static function newFactory()
    {
        return new ProductTypeFactory();
    }

    public function hasDependencies(): bool
    {
        return $this->products()->exists();
    }
}
