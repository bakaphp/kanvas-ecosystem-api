<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Categories\Models;

use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Categories\Observers\CategoryObserver;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Products\Models\ProductsCategories;
use Kanvas\Inventory\Traits\ScopesTrait;
use Kanvas\Languages\Traits\HasTranslationsDefaultFallback;
use Nevadskiy\Tree\AsTree;
use Override;

#[ObservedBy(CategoryObserver::class)]
class Categories extends BaseModel
{
    use UuidTrait;
    use SlugTrait;
    use ScopesTrait;
    use DatabaseSearchableTrait;
    use HasTranslationsDefaultFallback;
    use HasLightHouseCache;
    use AsTree;

    protected $table = 'categories';
    protected $guarded = [];

    public $translatable = ['name'];

    #[Override]
    public function getGraphTypeName(): string
    {
        return 'Category';
    }

    public function apps(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id', 'id');
    }

    public function companies(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id', 'id');
    }

    public function productsCategories(): HasMany
    {
        return $this->hasMany(ProductsCategories::class, 'categories_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Products::class, 'products_categories', 'categories_id', 'products_id');
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
     * Set the total amount of products of a product categories.
     */
    public function setTotalProducts(): int
    {
        $total = ProductsCategories::where('categories_id', $this->getId())
                ->where('is_deleted', 0)
                ->count();

        return (int) $total;
    }
}
