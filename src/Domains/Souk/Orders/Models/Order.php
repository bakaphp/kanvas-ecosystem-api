<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Souk\Models\BaseModel;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Laravel\Scout\Searchable;

/**
 * Class Order
 *
 * @property int $id
 * @property int $app_id
 * @property string $uuid
 * @property string|null $tracking_client_id
 * @property string|null $user_email
 * @property string|null $token
 * @property int|null $billing_address_id
 * @property int|null $shipping_address_id
 * @property int|null $user_id
 * @property float|null $total_gross_amount
 * @property float|null $total_net_amount
 * @property float|null $shipping_price_gross_amount
 * @property float|null $shipping_price_net_amount
 * @property float|null $discount_amount
 * @property string|null $discount_name
 * @property int|null $voucher_id
 * @property string|null $language_code
 * @property string $status
 * @property string|null $shipping_method_name
 * @property int|null $shipping_method_id
 * @property bool $display_gross_prices
 * @property string|null $translated_discount_name
 * @property string|null $customer_note
 * @property float|null $weight
 * @property string|null $checkout_token
 * @property string|null $currency
 * @property string|null $metadata
 * @property string|null $private_metadata
 * @property bool $is_deleted
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Order extends BaseModel
{
    use UuidTrait;
    use Searchable;
    use CanUseWorkflow;

    protected $table = 'leads';
    protected $guarded = [];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'user_id', 'id');
    }
}
