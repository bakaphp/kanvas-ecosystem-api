<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Expenses\Models;

use Baka\Casts\Json;
use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kanvas\Approvals\Traits\HasApprovals;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseReimbursementStatusEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Kanvas\Scribe\Models\BaseModel;
use Kanvas\Scribe\Payments\Models\Payment as ScribePayment;
use Kanvas\Users\Models\Users;
use Override;

/**
 * Scribe.Expense — non-bill spending (company card, employee-paid travel, direct bank debit, petty cash).
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property int|null $vendor_organization_id
 * @property string|null $expense_number
 * @property string|null $vendor_display_name
 * @property string|null $vendor_legal_name
 * @property string|null $vendor_tax_id
 * @property string|null $vendor_email
 * @property ExpenseStatusEnum $status
 * @property Carbon $expense_date
 * @property Carbon|null $submitted_at
 * @property Carbon|null $approved_at
 * @property int|null $approved_by_users_id
 * @property Carbon|null $rejected_at
 * @property int|null $rejected_by_users_id
 * @property string|null $reject_reason
 * @property Carbon|null $voided_at
 * @property string|null $void_reason_code
 * @property ExpensePaidByEnum $paid_by
 * @property int|null $paid_by_users_id
 * @property int|null $payment_method_id
 * @property int|null $bank_account_id
 * @property ExpenseReimbursementStatusEnum $reimbursement_status
 * @property int|null $reimbursement_payment_id
 * @property Carbon|null $reimbursed_at
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
    use HasApprovals;
    use DynamicSearchableTrait {
        search as public traitSearch;
    }
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

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'vendor_organization_id', 'id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'approved_by_users_id', 'id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'rejected_by_users_id', 'id');
    }

    public function paidByUser(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'paid_by_users_id', 'id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethods::class, 'payment_method_id', 'id');
    }

    public function reimbursementPayment(): BelongsTo
    {
        return $this->belongsTo(ScribePayment::class, 'reimbursement_payment_id', 'id');
    }

    protected function sourceDomainForLedger(): string
    {
        return 'Scribe';
    }

    public function searchableAs(): string
    {
        $model = ! $this->searchableDeleteRecord() ? $this : $this->withTrashed()->find($this->id);
        $app = $model->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_scribe_expense_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'scribe_expense_index');
    }

    #[Override]
    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }

    public function toSearchableArray(): array
    {
        return [
            'objectID' => "Kanvas\Scribe\Expenses\Models\Expense::{$this->id}",
            'id' => (string) $this->id,
            'uuid' => (string) $this->uuid,
            'apps_id' => $this->apps_id,
            'companies_id' => $this->companies_id,
            'users_id' => $this->users_id,
            'vendor_organization_id' => $this->vendor_organization_id,
            'expense_number' => (string) $this->expense_number,
            'vendor_display_name' => (string) $this->vendor_display_name,
            'vendor_legal_name' => (string) $this->vendor_legal_name,
            'vendor_email' => (string) $this->vendor_email,
            'vendor_tax_id' => (string) $this->vendor_tax_id,
            'external_id' => (string) $this->external_id,
            'notes' => (string) $this->notes,
            'status' => $this->status?->value,
            'paid_by' => $this->paid_by?->value,
            'reimbursement_status' => $this->reimbursement_status?->value,
            'source' => (string) $this->source,
            'currency' => (string) $this->currency,
            'total_native' => (float) $this->total_native,
            'total_base' => (float) $this->total_base,
            'expense_date' => $this->expense_date?->timestamp,
            'created_at' => $this->created_at?->timestamp,
            'updated_at' => $this->updated_at?->timestamp,
        ];
    }

    public function typesenseCollectionSchema(): array
    {
        return [
            'name' => $this->searchableAs(),
            'fields' => [
                ['name' => 'objectID', 'type' => 'string'],
                ['name' => 'id', 'type' => 'string'],
                ['name' => 'uuid', 'type' => 'string'],
                ['name' => 'apps_id', 'type' => 'int64', 'facet' => true],
                ['name' => 'companies_id', 'type' => 'int64', 'facet' => true],
                ['name' => 'users_id', 'type' => 'int64', 'optional' => true],
                ['name' => 'vendor_organization_id', 'type' => 'int64', 'optional' => true, 'facet' => true],
                ['name' => 'expense_number', 'type' => 'string', 'optional' => true],
                ['name' => 'vendor_display_name', 'type' => 'string', 'optional' => true],
                ['name' => 'vendor_legal_name', 'type' => 'string', 'optional' => true],
                ['name' => 'vendor_email', 'type' => 'string', 'optional' => true],
                ['name' => 'vendor_tax_id', 'type' => 'string', 'optional' => true],
                ['name' => 'external_id', 'type' => 'string', 'optional' => true],
                ['name' => 'notes', 'type' => 'string', 'optional' => true],
                ['name' => 'status', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'paid_by', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'reimbursement_status', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'source', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'currency', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'total_native', 'type' => 'float', 'optional' => true],
                ['name' => 'total_base', 'type' => 'float', 'optional' => true, 'sort' => true],
                ['name' => 'expense_date', 'type' => 'int64', 'optional' => true, 'sort' => true],
                ['name' => 'created_at', 'type' => 'int64', 'sort' => true],
                ['name' => 'updated_at', 'type' => 'int64', 'optional' => true],
            ],
            'default_sorting_field' => 'created_at',
        ];
    }

    public static function search($query = '', $callback = null)
    {
        $app = app(Apps::class);
        $searchQuery = self::traitSearch($query, $callback)->where('apps_id', $app->getId());

        $user = auth()->user();
        if ($user instanceof UserInterface && ! $user->isAppOwner()) {
            $searchQuery->where('companies_id', $user->getCurrentCompany()->getId());
        }

        if ($searchQuery->model->isTypesense()) {
            $searchQuery->options([
                'query_by' => 'expense_number,vendor_display_name,vendor_legal_name,vendor_email,vendor_tax_id,external_id',
            ]);
        }

        return $searchQuery;
    }
}
