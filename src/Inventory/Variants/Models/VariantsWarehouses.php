<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

/**
 * Class Variants Warehouse.
 *
 * @property int $products_variants_id
 * @property int $warehouses_id
 * @property int $quantity
 * @property float $price
 * @property string $sku
 * @property int $position
 * @property string $serial_number
 * @property int $is_default
 * @property int $is_oversellable
 * @property int $is_default
 * @property int $is_best_seller
 * @property int $is_on_sale
 * @property int $is_on_promo
 * @property int $can_pre_order
 * @property int $is_coming_soon
 * @property int $is_new
 * @property int $is_published
 * @property string $created_at
 * @property string $updated_at
 * @property bool $is_deleted
 */
class VariantsWarehouses extends BaseModel
{
    protected $table = 'products_variants_warehouses';
    protected $guarded = [];

    /**
     * channels.
     */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(
            Channels::class,
            VariantsChannels::class,
            'product_variants_warehouse_id',
            'channels_id'
        )
            ->withPivot(
                'price',
                'discounted_price',
                'is_published'
            );
    }

    public function variant(): HasMany
    {
        return $this->hasMany(Variants::class, 'products_variants_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouses::class, 'warehouses_id');
    }
}
