<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Models;

use Baka\Casts\Json;
use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Shopify\Traits\HasShopifyCustomField;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Social\Tags\Traits\HasTagsTrait;
use Kanvas\Souk\Models\BaseModel;
use Kanvas\Souk\Orders\DataTransferObject\OrderItem as OrderItemDto;
use Kanvas\Souk\Orders\Enums\OrderFulfillmentStatusEnum;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Observers\OrderObserver;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Override;
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
 * @property string|null $reference
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
#[ObservedBy(OrderObserver::class)]
class Order extends BaseModel
{
    use UuidTrait;
    use DynamicSearchableTrait;
    use CanUseWorkflow;
    use HasShopifyCustomField;
    use HasTagsTrait;

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
        'metadata' => Json::class,
        'private_metadata' => Json::class,
    ];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Regions::class, 'region_id', 'id');
    }

    public function people(): BelongsTo
    {
        return $this->belongsTo(People::class, 'people_id', 'id');
    }

    public function billingAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'billing_address_id', 'id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'id')->where('is_public', 1);
    }

    public function allItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'id');
    }

    public function shippingAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'shipping_address_id', 'id');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payments::class, 'payable');
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

    public function getSubTotalAmount(): float
    {
        return (float) $this->total_net_amount;
    }

    public function getTotalTaxAmount(): float
    {
        return $this->getTotalAmount() - $this->getSubTotalAmount();
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

    public function deleteItems(): void
    {
        $this->items()->delete();
    }

    public function fulfill(): void
    {
        $this->fulfillment_status = 'fulfilled';
        $this->saveOrFail();
    }

    public function fulfillCancelled(): void
    {
        $this->fulfillment_status = 'canceled';
        $this->saveOrFail();
    }

    public function completed(): void
    {
        $this->status = 'completed';
        $this->saveOrFail();
    }

    public function cancel(): void
    {
        $this->status = 'canceled';
        $this->saveOrFail();
    }

    public function scopeWhereNotCompleted(Builder $query): Builder
    {
        return $query->where('status', '!=', 'completed');
    }

    public function scopeWhereCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    public function scopeWhereCancelled(Builder $query): Builder
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeWhereFulfilled(Builder $query): Builder
    {
        return $query->where('fulfillment_status', 'fulfilled');
    }

    public function scopeWhereNotFulfilled(Builder $query): Builder
    {
        return $query->whereNotIn('fulfillment_status', ['fulfilled', 'canceled']);
    }

    public function scopeWhereDraft(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }

    public function isFulfilled(): bool
    {
        return $this->fulfillment_status === OrderFulfillmentStatusEnum::COMPLETED->value;
    }

    public function isCompleted(): bool
    {
        return $this->status === OrderStatusEnum::COMPLETED->value;
    }

    public function isFullyCompleted(): bool
    {
        return $this->isFulfilled() && $this->isCompleted();
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

    public function getEmail(): ?string
    {
        return $this->user_email ?? $this->people->getEmails()->first()?->email;
    }

    public function getPhone(): ?string
    {
        return $this->user_phone ?? $this->people->getPhones()->first()?->phone;
    }

    public function addMetadata(string $key, mixed $value): void
    {
        $metadata = $this->metadata ?? [];
        $metadata[$key] = $value;

        $this->metadata = $metadata;
        $this->saveOrFail();
    }

    public function addPrivateMetadata(string $key, mixed $value): void
    {
        $metadata = $this->private_metadata ?? [];
        $metadata[$key] = $value;

        $this->private_metadata = $metadata;
        $this->saveOrFail();
    }

    public function getMetadata(string $key): mixed
    {
        if ($this->metadata === null) {
            return null;
        }

        return $this->metadata[$key] ?? null;
    }

    public function getPrivateMetadata(string $key): mixed
    {
        if ($this->private_metadata === null) {
            return null;
        }

        return $this->private_metadata[$key] ?? null;
    }

    #[Override]
    public function shouldBeSearchable(): bool
    {
        return true;
    }

    public function getOrderNumber(): int
    {
        $key = $this->app->get('use_integration_order_number');

        if (! empty($key) && $value = $this->get($key)) {
            return (int) $value;
        }

        return $this->order_number;
    }

    /**
     * The Typesense schema to be created for the Order model.
     * Note: Currently, Order model has shouldBeSearchable() set to return false.
     * This schema would be used if that changes in the future.
     */
    public function typesenseCollectionSchema(): array
    {
        return [
            'name' => $this->searchableAs(),
            'fields' => [
                [
                    'name' => 'objectID',
                    'type' => 'string',
                ],
                [
                    'name' => 'id',
                    'type' => 'int64',
                ],
                [
                    'name' => 'uuid',
                    'type' => 'string',
                ],
                [
                    'name' => 'apps_id',
                    'type' => 'int64',
                ],
                [
                    'name' => 'companies_id',
                    'type' => 'int64',
                    'facet' => true,
                ],
                [
                    'name' => 'region_id',
                    'type' => 'int64',
                    'facet' => true,
                ],
                [
                    'name' => 'order_number',
                    'type' => 'int64',
                    'sort' => true,
                ],
                [
                    'name' => 'tracking_client_id',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'user_email',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'user_phone',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'users_id',
                    'type' => 'int64',
                    'optional' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'people_id',
                    'type' => 'int64',
                    'optional' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'total_gross_amount',
                    'type' => 'float',
                    'optional' => true,
                    'sort' => true,
                ],
                [
                    'name' => 'total_net_amount',
                    'type' => 'float',
                    'optional' => true,
                    'sort' => true,
                ],
                [
                    'name' => 'shipping_price_gross_amount',
                    'type' => 'float',
                    'optional' => true,
                ],
                [
                    'name' => 'shipping_price_net_amount',
                    'type' => 'float',
                    'optional' => true,
                ],
                [
                    'name' => 'discount_amount',
                    'type' => 'float',
                    'optional' => true,
                ],
                [
                    'name' => 'discount_name',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'language_code',
                    'type' => 'string',
                    'optional' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'status',
                    'type' => 'string',
                    'facet' => true,
                ],
                [
                    'name' => 'fulfillment_status',
                    'type' => 'string',
                    'optional' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'shipping_method_name',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'shipping_method_id',
                    'type' => 'int64',
                    'optional' => true,
                ],
                [
                    'name' => 'currency',
                    'type' => 'string',
                    'optional' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'customer_note',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'weight',
                    'type' => 'float',
                    'optional' => true,
                ],
                [
                    'name' => 'estimate_shipping_date',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'shipped_date',
                    'type' => 'string',
                    'optional' => true,
                ],
                [
                    'name' => 'payment_gateway_names',
                    'type' => 'string[]',
                    'optional' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'items',
                    'type' => 'object[]',
                    'optional' => true,
                ],
                [
                    'name' => 'people',
                    'type' => 'object',
                    'optional' => true,
                ],
                [
                    'name' => 'region',
                    'type' => 'object',
                    'optional' => true,
                ],
                [
                    'name' => 'billing_address',
                    'type' => 'object',
                    'optional' => true,
                ],
                [
                    'name' => 'shipping_address',
                    'type' => 'object',
                    'optional' => true,
                ],
                [
                    'name' => 'tags',
                    'type' => 'string[]',
                    'optional' => true,
                    'facet' => true,
                ],
                [
                    'name' => 'metadata',
                    'type' => 'object',
                    'optional' => true,
                ],
                [
                    'name' => 'created_at',
                    'type' => 'int64',
                    'sort' => true,
                ],
                [
                    'name' => 'updated_at',
                    'type' => 'int64',
                    'optional' => true,
                ],
            ],
            'default_sorting_field' => 'created_at',
            'enable_nested_fields' => true,
        ];
    }

    /**
     * Define the searchable index name.
     */
    public function searchableAs(): string
    {
        $app = $this->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_order_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'orders');
    }

    public function setOrderType(string $orderType): void
    {
        $orderType = OrderTypes::firstOrCreate([
            'apps_id' => $this->apps_id,
            'name' => $orderType,
        ], [
            'apps_id' => $this->apps_id,
            'name' => $orderType,
        ]);

        $this->order_types_id = $orderType->id;
        $this->saveOrFail();
    }

    public function checkPayments()
    {
        if ($this && ($this->payments)) {
            $totalPaid = $this->getPaidAmount();
            $totalDebt = $this->total_net_amount - $totalPaid;
            if ($totalDebt <= 0) {
                $this->fulfill();
            }
        }
    }


    public function getPaidAmount()
    {
        return $this->payments()->where('status', PaymentStatusEnum::PAID->value)->sum('amount');
    }
}
