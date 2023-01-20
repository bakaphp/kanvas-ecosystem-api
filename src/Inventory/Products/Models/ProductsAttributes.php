<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Products\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Inventory\Models\BaseModel;

/**
 * Class Products.
 *
 * @property int $id
 * @property int $products_id
 * @property int $attributes_id
 * @property string $value
 * @property string $created_at
 * @property string $updated_at
 * @property bool $is_deleted
 */
class ProductAttributes extends BaseModel
{
    protected $table = 'products_attributes';
    protected $guarded = [];

    /**
     * Get the product.
     *
     * @return BelongsTo
     */
    public function product() : BelongsTo
    {
        return $this->belongsTo(Products::class, 'products_id');
    }

    /**
     * Get the attribute.
     *
     * @return BelongsTo
     */
    public function attribute() : BelongsTo
    {
        return $this->belongsTo(Attributes::class, 'attributes_id');
    }
}
