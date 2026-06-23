<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Invoices\Enums\AllocationSourceTypeEnum;
use Kanvas\Scribe\Invoices\Enums\AllocationStatusEnum;
use Kanvas\Scribe\Invoices\Enums\ReversalReasonCodeEnum;
use Kanvas\Scribe\Payments\Models\Payment;
use Kanvas\Users\Models\Users;

/**
 * Maps one Souk.Payments (or credit_note, prepayment, overpayment) to N invoices.
 *
 * Reversals are status flips (active → reversed), never row deletes. JE reversals are mirror JEs (a separate
 * journal_entries row with is_reversal_of set).
 *
 * @see plan §5 accounting.invoice_payment_allocations
 * @see plan §7.7 — "Reversals preserve history"
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property int $invoice_id
 * @property int|null $payment_id
 * @property string $source_type
 * @property int|null $source_id
 * @property string $status
 * @property float $amount_native
 * @property float $amount_base
 * @property string $currency
 * @property float $fx_rate_to_base
 * @property Carbon|null $fx_rate_at
 * @property Carbon $allocated_at
 * @property int|null $allocated_by_users_id
 * @property Carbon|null $reversed_at
 * @property int|null $reversed_by_users_id
 * @property string|null $reversal_reason
 * @property string|null $reversal_reason_code
 * @property string|null $reversal_external_id
 * @property string $source
 * @property string|null $external_id
 * @property string|null $idempotency_key
 * @property array|null $metadata
 */
class InvoicePaymentAllocation extends EloquentModel
{
    use UuidTrait;

    protected $connection = 'accounting';
    protected $table = 'invoice_payment_allocations';
    protected $guarded = [];

    protected $casts = [
        'status' => AllocationStatusEnum::class,
        'source_type' => AllocationSourceTypeEnum::class,
        'reversal_reason_code' => ReversalReasonCodeEnum::class,
        'amount_native' => 'float',
        'amount_base' => 'float',
        'fx_rate_to_base' => 'float',
        'allocated_at' => 'datetime',
        'reversed_at' => 'datetime',
        'fx_rate_at' => 'datetime',
        'metadata' => Json::class,
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id', 'id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id', 'id');
    }

    public function allocatedBy(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'allocated_by_users_id', 'id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'reversed_by_users_id', 'id');
    }

    public function isActive(): bool
    {
        return $this->status === AllocationStatusEnum::ACTIVE;
    }
}
