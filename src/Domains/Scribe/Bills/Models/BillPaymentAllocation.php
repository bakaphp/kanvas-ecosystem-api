<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Scribe\Invoices\Enums\AllocationStatusEnum;

/**
 * Maps one Souk.Payments (outbound) — or a vendor_credit / prepayment / wallet adjustment — to N Bills.
 *
 * Mirrors InvoicePaymentAllocation on the AP side. Reversals are status flips, never row deletes.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property int $bill_id
 * @property int|null $payment_id
 * @property string $source_type
 * @property int|null $source_id
 * @property AllocationStatusEnum $status
 * @property float $amount_native
 * @property float $amount_base
 * @property string $currency
 * @property float $fx_rate_to_base
 * @property \Illuminate\Support\Carbon|null $fx_rate_at
 * @property \Illuminate\Support\Carbon|null $allocated_at
 * @property int|null $allocated_by_users_id
 * @property \Illuminate\Support\Carbon|null $reversed_at
 * @property int|null $reversed_by_users_id
 * @property string|null $reversal_reason
 * @property string|null $reversal_reason_code
 * @property string|null $reversal_external_id
 * @property string $source
 * @property string|null $external_id
 * @property string|null $idempotency_key
 * @property array|null $metadata
 */
class BillPaymentAllocation extends EloquentModel
{
    use UuidTrait;

    protected $connection = 'accounting';
    protected $table = 'bill_payment_allocations';
    protected $guarded = [];

    protected $casts = [
        'status' => AllocationStatusEnum::class,
        'amount_native' => 'float',
        'amount_base' => 'float',
        'fx_rate_to_base' => 'float',
        'fx_rate_at' => 'datetime',
        'allocated_at' => 'datetime',
        'reversed_at' => 'datetime',
        'metadata' => Json::class,
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'bill_id', 'id');
    }
}
