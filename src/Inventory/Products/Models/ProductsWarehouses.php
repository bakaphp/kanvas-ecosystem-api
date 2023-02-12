<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Models;

use Baka\Traits\HasCompositePrimaryKeyTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

/**
 * Class Products Categories.
 *
 * @property int $products_id
 * @property int $warehouses_id
 * @property string $created_at
 * @property string $updated_at
 * @property bool $is_deleted
 */
class ProductsWarehouses extends BaseModel
{
    use HasCompositePrimaryKeyTrait;

    protected $table = 'products_warehouses';
    protected $guarded = [
        'products_id',
        'warehouses_id'
    ];

    protected $primaryKey = ['products_id', 'warehouses_id'];

    /**
     * Get the product.
     *
     * @return BelongsTo
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Products::class, 'products_id');
    }

    /**
     * Get the warehouse.
     *
     * @return BelongsTo
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouses::class, 'warehouses_id');
    }
}
