<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Expenses\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseReimbursementStatusEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Kanvas\Scribe\Models\BaseModel;

/**
 * Scribe.Expense — non-bill spending (company card, employee-paid travel, direct bank debit, petty cash).
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property string|null $expense_number
 * @property string|null $vendor_billable_type
 * @property int|null $vendor_billable_id
 * @property string|null $vendor_display_name
 * @property string|null $vendor_legal_name
 * @property string|null $vendor_tax_id
 * @property string|null $vendor_email
 * @property ExpenseStatusEnum $status
 * @property \Illuminate\Support\Carbon $expense_date
 * @property \Illuminate\Support\Carbon|null $submitted_at
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property int|null $approved_by_users_id
 * @property \Illuminate\Support\Carbon|null $rejected_at
 * @property int|null $rejected_by_users_id
 * @property string|null $reject_reason
 * @property \Illuminate\Support\Carbon|null $voided_at
 * @property string|null $void_reason_code
 * @property ExpensePaidByEnum $paid_by
 * @property int|null $paid_by_users_id
 * @property int|null $payment_method_id
 * @property int|null $bank_account_id
 * @property ExpenseReimbursementStatusEnum $reimbursement_status
 * @property int|null $reimbursement_payment_id
 * @property \Illuminate\Support\Carbon|null $reimbursed_at
 * @property string $currency
 * @property float $fx_rate_to_base
 * @property float $subtotal_native
 * @property float $tax_native
 * @property float $total_native
 * @property float $subtotal_base
 * @property float $tax_base
 * @property float $total_base
 * @property array|null $tax_metadata
 * @property array|null $regional_compliance
 * @property string|null $notes
 * @property string|null $internal_notes
 * @property string $source
 * @property string|null $external_id
 * @property string|null $external_url
 * @property JournalEntryOriginEnum $origin
 * @property array|null $metadata
 * @property bool $is_deleted
 * @property int|null $users_id
 */
class Expense extends BaseModel
{
    use EmitsLedgerEventsForEntity;
    use UuidTrait;

    protected $table = 'expenses';
    protected $guarded = [];

    protected $casts = [
        'status' => ExpenseStatusEnum::class,
        'paid_by' => ExpensePaidByEnum::class,
        'reimbursement_status' => ExpenseReimbursementStatusEnum::class,
        'origin' => JournalEntryOriginEnum::class,
        'is_deleted' => 'boolean',
        'expense_date' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'voided_at' => 'datetime',
        'reimbursed_at' => 'datetime',
        'fx_rate_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'fx_rate_to_base' => 'float',
        'subtotal_native' => 'float',
        'tax_native' => 'float',
        'total_native' => 'float',
        'subtotal_base' => 'float',
        'tax_base' => 'float',
        'total_base' => 'float',
        'tax_metadata' => Json::class,
        'regional_compliance' => Json::class,
        'metadata' => Json::class,
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseLine::class, 'expense_id', 'id')->orderBy('sort_order');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(ExpenseReceipt::class, 'expense_id', 'id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id', 'id');
    }

    protected function sourceDomainForLedger(): string
    {
        return 'Scribe';
    }
}
