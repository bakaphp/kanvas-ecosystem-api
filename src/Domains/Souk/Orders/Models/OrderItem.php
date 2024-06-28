<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Models;

use Baka\Traits\NoCompanyRelationshipTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Models\BaseModel;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Laravel\Scout\Searchable;

/**
 * Class OrderItem
 *
 * @property int $id
 * @property int $apps_id
 * @property string $uuid
 * @property string $product_name
 * @property string $product_sku
 * @property int $quantity
 * @property float|null $unit_price_net_amount
 * @property float|null $unit_price_gross_amount
 * @property bool $is_shipping_required
 * @property int $order_id
 * @property int $quantity_fulfilled
 * @property int $variant_id
 * @property float|null $tax_rate
 * @property string|null $translated_product_name
 * @property string|null $currency
 * @property string|null $translated_variant_name
 * @property string $variant_name
 * @property bool $is_deleted
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */

class OrderItem extends BaseModel
{
    use UuidTrait;
    //use Searchable;
    use CanUseWorkflow;
    use NoCompanyRelationshipTrait;

    protected $table = 'order_items';
    protected $guarded = [];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variants::class, 'variant_id', 'id');
    }
}
