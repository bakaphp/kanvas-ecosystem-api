<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Models;

use Baka\Casts\Json;
use Baka\Observers\ClearsLightHouseCacheObserver;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Models\BaseModel;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\SalesOrders\Enums\PoSourceEnum;
use Kanvas\Scribe\SalesOrders\Enums\SalesOrderStatusEnum;
use Override;

/**
 * What a customer committed to buy: their purchase order, or our proforma signed and stamped by them.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property string $order_number
 * @property SalesOrderStatusEnum $document_status
 * @property PoSourceEnum $po_source
 * @property int $customer_organization_id
 * @property int|null $quote_id
 * @property string|null $customer_po_number
 * @property Carbon|null $customer_po_date
 * @property array|null $signature_checklist
 * @property string $currency
 * @property string $order_total
 * @property string|null $incoterm
 * @property string|null $consignee
 * @property int|null $payment_terms_days
 * @property Carbon|null $requested_date
 * @property string|null $notes
 * @property array|null $review
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $feasibility_confirmed_at
 * @property Carbon|null $etd
 * @property Carbon|null $eta
 * @property Carbon $received_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $returned_at
 * @property string|null $return_reason
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property int|null $users_id
 * @property bool $is_deleted
 */
#[ObservedBy([ClearsLightHouseCacheObserver::class])]
class SalesOrder extends BaseModel
{
    use HasLightHouseCache;
    use UuidTrait;

    protected $table = 'sales_orders';
    protected $guarded = [];

    protected $casts = [
        'document_status' => SalesOrderStatusEnum::class,
        'po_source' => PoSourceEnum::class,
        'customer_po_date' => 'date',
        'signature_checklist' => Json::class,
        'order_total' => 'decimal:4',
        'requested_date' => 'date',
        'review' => Json::class,
        'reviewed_at' => 'datetime',
        'feasibility_confirmed_at' => 'datetime',
        'etd' => 'date',
        'eta' => 'date',
        'received_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'accepted_at' => 'datetime',
        'returned_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'is_deleted' => 'boolean',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class, 'sales_order_id', 'id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'customer_organization_id', 'id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'quote_id', 'id');
    }

    /**
     * The differences the last review found between the order and its quote.
     *
     * @return list<array{key: string, field: string, po_value: string|null, quote_value: string|null, resolution: string|null}>
     */
    public function differences(): array
    {
        return $this->review['differences'] ?? [];
    }

    /**
     * The differences still waiting for a written clarification.
     *
     * @return list<array{key: string, field: string, po_value: string|null, quote_value: string|null, resolution: null}>
     */
    public function openDifferences(): array
    {
        return array_values(array_filter(
            $this->differences(),
            static fn (array $difference): bool => $difference['resolution'] === null
        ));
    }

    #[Override]
    public function getGraphTypeName(): string
    {
        return 'ScribeSalesOrder';
    }
}
