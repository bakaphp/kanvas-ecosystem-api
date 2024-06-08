<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Models;

use Awobaz\Compoships\Compoships;
use Baka\Traits\HasCompositePrimaryKeyTrait;
use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Channels\Models\VariantChannelPriceHistory;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

/**
 * Class Variants Channels.
 *
 * @property int $products_variants_id
 * @property int $warehouses_id
 * @property int $channels_id
 * @property float $price
 * @property float $discount_price
 * @property string $created_at
 * @property string $updated_at
 * @property bool $is_deleted
 *
 * @todo Add relationships and cascade softdelete
 */
class VariantsChannels extends BaseModel
{
    use HasCompositePrimaryKeyTrait;
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;
    use Compoships;

    protected $table = 'products_variants_channels';
    protected $guarded = [];

    protected $primaryKey = ['product_variants_warehouse_id', 'channels_id'];
    protected $forceDeleting = true;

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channels::class, 'channels_id');
    }

    public function productVariantWarehouse(): BelongsTo
    {
        return $this->belongsTo(VariantsWarehouses::class, 'product_variants_warehouse_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variants::class, 'products_variants_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouses::class, 'warehouses_id');
    }

    public function pricesHistory(): HasMany
    {
        return $this->hasMany(
            VariantChannelPriceHistory::class,
            ['product_variants_warehouse_id','channels_id'],
            ['product_variants_warehouse_id','channels_id']
        );
    }
}
