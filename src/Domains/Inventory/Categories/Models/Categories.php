<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Categories\Models;

use Baka\Traits\DatabaseSearchableTrait;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
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
    protected $withCount = ['activeProducts'];
    protected ?int $totalProducts = null;

    public $translatable = ['name'];

    #[Override]
    public function getGraphTypeName(): string
    {
        return 'Category';
    }

    #[Override]
    public function getTable()
    {
        $databaseName = DB::connection($this->connection)->getDatabaseName();

        return $databaseName . '.categories';
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
     * Exists so `$withCount` can carry the filters — `$withCount` is a plain property, it
     * cannot hold the constraint closure that `withCount(['products' => fn ...])` would take.
     */
    public function activeProducts(): BelongsToMany
    {
        return $this->products()
            ->where('products.is_deleted', 0)
            ->where('products_categories.is_deleted', 0);
    }

    public function categoryEntities(): HasMany
    {
        return $this->hasMany(CategoryEntity::class, 'categories_id');
    }

    public function getProductsByTags(string $tag): Collection
    {
        return $this->products()
             ->whereHas('tags', function ($query) use ($tag) {
                 $query->where('name', $tag);
             })
             ->inRandomOrder()
             ->get();
    }

    /**
     * Counted live instead of from a cached `total_products` custom field: products are
     * attached/detached through the pivot's query builder (`ProductsCategories` is not an
     * `AsPivot`, so `belongsToMany` never sets `using()`), which fires no model event —
     * any cached counter freezes at whatever it held the first time it was written.
     *
     * The count normally rides along on the row via `$withCount`, so resolving this field
     * over a list costs no extra query; the fallback only fires on a model built outside
     * `newQuery()` (`make()`, `newFromBuilder()` with a hand-rolled select).
     */
    public function getTotalProducts(): int
    {
        return $this->totalProducts ??= (int) ($this->active_products_count
            ?? $this->activeProducts()->count());
    }
}
