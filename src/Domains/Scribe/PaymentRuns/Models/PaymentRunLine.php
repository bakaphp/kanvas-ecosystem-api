<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PaymentRuns\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Models\BaseModel;
use Kanvas\Scribe\PaymentRuns\Enums\PaymentRunLineStatusEnum;

/**
 * @property int $id
 * @property int $payment_run_id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $bill_id
 * @property int|null $bank_account_id
 * @property string|null $vendor_name
 * @property string|null $bill_number
 * @property Carbon|null $due_date
 * @property string $currency
 * @property float $amount_native
 * @property PaymentRunLineStatusEnum $status
 * @property Carbon|null $paid_at
 * @property bool $is_deleted
 */
class PaymentRunLine extends BaseModel
{
    protected $table = 'payment_run_lines';
    protected $guarded = [];

    protected $casts = [
        'status' => PaymentRunLineStatusEnum::class,
        'due_date' => 'date',
        'amount_native' => 'float',
        'paid_at' => 'datetime',
        'is_deleted' => 'boolean',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PaymentRun::class, 'payment_run_id', 'id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'bill_id', 'id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id', 'id');
    }
}
