<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Connectors\Shopify\Traits\HasShopifyCustomField;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Souk\Models\BaseModel;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem as OrderItemDto;
use Kanvas\Souk\Orders\Observers\OrderObserver;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Laravel\Scout\Searchable;
use Spatie\LaravelData\DataCollection;

/**
 * Class Order
 *
 * @property int $id
 * @property int $apps_id
 * @property int companies_id
 * @property int $region_id
 * @property string $uuid
 * @property string|null $tracking_client_id
 * @property string|null $user_email
 * @property string|null $user_phone
 * @property string|null $token
 * @property int|null $billing_address_id
 * @property int|null $shipping_address_id
 * @property int|null $users_id
 * @property int|null $people_id
 * @property int $order_number
 * @property float|null $total_gross_amount
 * @property float|null $total_net_amount
 * @property float|null $shipping_price_gross_amount
 * @property float|null $shipping_price_net_amount
 * @property float|null $discount_amount
 * @property string|null $discount_name
 * @property int|null $voucher_id
 * @property string|null $language_code
 * @property string $status
 * @property string|null $fulfillment_status
 * @property string|null $shipping_method_name
 * @property string|null $fulfillment_status
 * @property int|null $shipping_method_id
 * @property bool $display_gross_prices
 * @property string|null $translated_discount_name
 * @property string|null $customer_note
 * @property float|null $weight
 * @property string|null $checkout_token
 * @property string|null $currency
 * @property string|null $metadata
 * @property string|null $private_metadata
 * @property string|null $estimate_shipping_date
 * @property string|null $shipped_date
 * @property string|null $payment_gateway_names
 * @property bool $is_deleted
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
#[ObservedBy(OrderObserver::class)]
class Order extends BaseModel
{
    use UuidTrait;
    use Searchable;
    use CanUseWorkflow;
    use HasShopifyCustomField;

    protected $table = 'orders';
    protected $guarded = [];

    protected $casts = [
        'total_gross_amount' => 'float',
        'total_net_amount' => 'float',
        'shipping_price_gross_amount' => 'float',
        'shipping_price_net_amount' => 'float',
        'discount_amount' => 'float',
        'weight' => 'float',
        'payment_gateway_names' => Json::class,
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Regions::class, 'region_id', 'id');
    }

    public function people(): BelongsTo
    {
        return $this->belongsTo(People::class, 'people_id', 'id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'user_id', 'id');
    }

    public function scopeFilterByUser(Builder $query, mixed $user = null): Builder
    {
        $user = $user instanceof UserInterface ? $user : auth()->user();

        if (! $user->isAppOwner()) {
            return $query->where('users_id', $user->getId());
        }

        return $query;
    }

    public function getTotalAmount(): float
    {
        return (float) $this->total_gross_amount;
    }

    public function addItems(DataCollection $items): void
    {
        foreach ($items as $item) {
            $this->addItem($item);
        }
    }

    public function addItem(OrderItemDto $item): OrderItem
    {
        $orderItem = new OrderItem();
        $orderItem->order_id = $this->getId();
        $orderItem->apps_id = $this->apps_id;
        $orderItem->product_name = $item->variant->product->name;
        $orderItem->product_sku = $item->sku;
        $orderItem->quantity = $item->quantity;
        $orderItem->unit_price_net_amount = $item->price;
        $orderItem->unit_price_gross_amount = $item->price;
        $orderItem->is_shipping_required = true;
        $orderItem->quantity_fulfilled = 0;
        $orderItem->variant_id = $item->variant->getId();
        $orderItem->tax_rate = 0;
        $orderItem->currency = $item->currency->code;
        $orderItem->variant_name = $item->variant->name;
        $orderItem->saveOrFail();

        return $orderItem;
    }

    public function fulfill(): void
    {
        $this->fulfillment_status = 'fulfilled';
        $this->saveOrFail();
    }

    public function fulfillCancelled(): void
    {
        $this->fulfillment_status = 'cancelled';
        $this->saveOrFail();
    }

    public function completed(): void
    {
        $this->status = 'completed';
        $this->saveOrFail();
    }

    public function cancel(): void
    {
        $this->status = 'cancelled';
        $this->saveOrFail();
    }

    public function generateOrderNumber(): int
    {
        // Lock the orders table while retrieving the last order
        $lastOrder = Order::where('companies_id', $this->companies_id)
                        ->where('apps_id', $this->apps_id)
                        ->lockForUpdate() // Ensure no race conditions
                        ->latest('id')
                        ->first();

        $lastOrderNumber = $lastOrder ? intval($lastOrder->order_number) : 0;
        $newOrderNumber = $lastOrderNumber + 1;

        return $newOrderNumber;
    }
}
