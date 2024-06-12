<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Models\BaseModel;
use Kanvas\Inventory\Status\Models\Status;
use Kanvas\Inventory\Status\Models\VariantWarehouseStatusHistory;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

/**
 * Class Variants Warehouse.
 *
 * @property int $products_variants_id
 * @property int $warehouses_id
 * @property int $quantity
 * @property float $price
 * @property string $sku
 * @property int $status_id
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
 * @property string $created_at
 * @property string $updated_at
 * @property bool $is_deleted
 */
class VariantsWarehouses extends BaseModel
{
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;
    use CascadeSoftDeletes;

    protected $table = 'products_variants_warehouses';
    protected $cascadeDeletes = ['variantWarehousesStatusHistory', 'pricesHistory', 'variantChannels'];

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

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variants::class, 'products_variants_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouses::class, 'warehouses_id');
    }

    public function status(): HasOne
    {
        return $this->hasOne(Status::class, 'id', 'status_id');
    }

    public function statusHistory(): BelongsToMany
    {
        return $this->belongsToMany(
            Status::class,
            VariantWarehouseStatusHistory::class,
            'products_variants_warehouse_id',
            'status_id'
        )
            ->withPivot('from_date');
    }

    public function pricesHistory(): HasMany
    {
        return $this->hasMany(
            VariantsWarehousesPriceHistory::class,
            'product_variants_warehouse_id'
        );
    }

    public function variantWarehousesStatusHistory(): HasMany
    {
        return $this->hasMany(
            VariantWarehouseStatusHistory::class,
            'products_variants_warehouse_id'
        );
    }

    public function variantChannels(): HasMany
    {
        return $this->hasMany(VariantsChannels::class, 'product_variants_warehouse_id')->where('is_published', 1);
    }

    /**
     * Get the status history with the status information.
     *
     * @return array
     */
    public function getStatusHistory(): array
    {
        $statusHistories = [];

        foreach ($this->statusHistory as $status) {
            $statusHistories[] = [
                "id" => $status->id,
                "name" => $status->name,
                "from_date" => $status->pivot->from_date
            ];
        };

        return $statusHistories;
    }

    public function getTotalProducts(): int
    {
        $total = VariantsWarehouses::where('warehouses_id', $this->warehouse->getId())
                ->where('is_deleted', 0)
                ->sum('quantity');
        return (int) $total;
    }
}
