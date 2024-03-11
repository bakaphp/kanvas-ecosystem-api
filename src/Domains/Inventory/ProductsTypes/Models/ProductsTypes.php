<?php

declare(strict_types=1);

namespace Kanvas\Inventory\ProductsTypes\Models;

use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Products\Models\Products;
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

    protected $table = 'products_types';

    protected $guarded = [];

    /**
     * Get the user that owns the ProductsTypes.
     *
     * @return BelongsTo
     */
    public function companies(): BelongsTo
    {
        return $this->belongsTo(Companies::class, 'companies_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Products::class, 'products_types_id');
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
     * Set the total amount of products of a product type.
     *
     * @return Int
     */
    public function setTotalProducts(): int
    {
        $total = Products::where('products_types_id', $this->getId())
                ->where('is_deleted', 0)
                ->count();

        $this->set('total_products', $total);

        return (int) $total;
    }
}
