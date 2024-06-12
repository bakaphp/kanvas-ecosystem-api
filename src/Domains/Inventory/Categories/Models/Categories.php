<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Categories\Models;

use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Products\Models\ProductsCategories;
use Kanvas\Inventory\Traits\DatabaseSearchableTrait;
use Kanvas\Inventory\Traits\ScopesTrait;

class Categories extends BaseModel
{
    use UuidTrait;
    use SlugTrait;
    use ScopesTrait;
    use DatabaseSearchableTrait;

    protected $table = 'categories';
    protected $guarded = [];

    /**
     *
     * @return BelongsTo
     */
    public function apps(): BelongsTo
    {
        return $this->belongsTo(Apps::class, 'apps_id', 'id');
    }

    /**
     * companies.
     *
     * @return BelongsTo
     */
    public function companies(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id', 'id');
    }

    public function productsCategories(): HasMany
    {
        return $this->hasMany(ProductsCategories::class, 'categories_id');
    }

    /**
     * Get the total amount of products of a product type.
     *
     * @return Int
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
     *
     * @return Int
     */
    public function setTotalProducts(): int
    {
        $total = ProductsCategories::where('categories_id', $this->getId())
                ->where('is_deleted', 0)
                ->count();

        return (int) $total;
    }
}
