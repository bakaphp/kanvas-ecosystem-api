<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Models;

use Awobaz\Compoships\Compoships;
use Baka\Traits\HasCompositePrimaryKeyTrait;
use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Models\BaseModel;

/**
 * Class Products Categories.
 *
 * @property int $products_id
 * @property int $categories_id
 * @property string $created_at
 * @property string $updated_at
 * @property bool $is_deleted
 */
class ProductsCategories extends BaseModel
{
    use HasCompositePrimaryKeyTrait;
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;
    use Compoships;

    protected $table = 'products_categories';
    protected $fillable = [
        'products_id',
        'categories_id'
    ];

    protected $primaryKey = ['products_id', 'categories_id'];

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
     * Get the category.
     *
     * @return BelongsTo
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Categories::class, 'categories_id');
    }
}
