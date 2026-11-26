<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PaymentReceipts\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Models\BaseModel;
use Kanvas\Scribe\Payments\Models\Payment;

/**
 * The receipt a customer gets for one payment, with the invoices it covered frozen at issue time.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property int $payment_id
 * @property string $receipt_number
 * @property int|null $customer_organization_id
 * @property string $customer_name
 * @property string $currency
 * @property float $amount
 * @property list<array{invoice_id: int, invoice_number: string|null, amount: float}> $invoices
 * @property string|null $payer_bank
 * @property string|null $instrument_number
 * @property Carbon|null $instrument_date
 * @property string|null $received_by
 * @property Carbon $issued_at
 * @property int|null $issued_by_users_id
 * @property bool $is_deleted
 */
class PaymentReceipt extends BaseModel
{
    use UuidTrait;

    protected $table = 'payment_receipts';
    protected $guarded = [];

    protected $casts = [
        'amount' => 'float',
        'invoices' => Json::class,
        'instrument_date' => 'date',
        'issued_at' => 'datetime',
        'is_deleted' => 'boolean',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id', 'id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'customer_organization_id', 'id');
    }
}
