<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesReceipts\Models;

use Baka\Casts\Json;
use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Models\BaseModel;
use Kanvas\Scribe\SalesReceipts\Enums\SalesReceiptStatusEnum;
use Override;

/**
 * Scribe.SalesReceipt — cash sale; customer paid at the moment of sale, no AR cycle.
 *
 * Lifecycle: RECORDED → VOIDED (no draft state — the sale already happened).
 * JE posts immediately on creation (DR Cash / CR Revenue + CR Tax Payable per plan §11.3).
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property int|null $customer_organization_id
 * @property string|null $receipt_number
 * @property string|null $billable_display_name
 * @property string|null $billable_legal_name
 * @property string|null $billable_tax_id
 * @property string|null $billable_email
 * @property array|null $billing_address_snapshot
 * @property SalesReceiptStatusEnum $status
 * @property Carbon $receipt_date
 * @property Carbon|null $voided_at
 * @property string|null $void_reason_code
 * @property string $tax_calculation_mode
 * @property string $currency
 * @property float $fx_rate_to_base
 * @property float $subtotal_native
 * @property float $tax_native
 * @property float $discount_native
 * @property float $total_native
 * @property float $subtotal_base
 * @property float $tax_base
 * @property float $discount_base
 * @property float $total_base
 * @property array|null $tax_metadata
 * @property array|null $regional_compliance
 * @property int|null $cash_account_id
 * @property int|null $payment_method_id
 * @property int|null $payment_id
 * @property string|null $notes
 * @property string|null $internal_notes
 * @property string $source
 * @property string|null $external_id
 * @property string|null $external_url
 * @property JournalEntryOriginEnum $origin
 * @property array|null $metadata
 * @property bool $is_deleted
 */
class SalesReceipt extends BaseModel
{
    use DynamicSearchableTrait {
        search as public traitSearch;
    }
    use EmitsLedgerEventsForEntity;
    use UuidTrait;

    protected $table = 'sales_receipts';
    protected $guarded = [];

    protected $casts = [
        'status' => SalesReceiptStatusEnum::class,
        'origin' => JournalEntryOriginEnum::class,
        'is_deleted' => 'boolean',
        'receipt_date' => 'date',
        'voided_at' => 'datetime',
        'fx_rate_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'fx_rate_to_base' => 'float',
        'subtotal_native' => 'float',
        'tax_native' => 'float',
        'discount_native' => 'float',
        'total_native' => 'float',
        'subtotal_base' => 'float',
        'tax_base' => 'float',
        'discount_base' => 'float',
        'total_base' => 'float',
        'billing_address_snapshot' => Json::class,
        'tax_metadata' => Json::class,
        'regional_compliance' => Json::class,
        'metadata' => Json::class,
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(SalesReceiptLine::class, 'sales_receipt_id', 'id')->orderBy('sort_order');
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cash_account_id', 'id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'customer_organization_id', 'id');
    }

    protected function sourceDomainForLedger(): string
    {
        return 'Scribe';
    }

    public function searchableAs(): string
    {
        $model = ! $this->searchableDeleteRecord() ? $this : $this->withTrashed()->find($this->id);
        $app = $model->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_scribe_sales_receipt_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'scribe_sales_receipt_index');
    }

    #[Override]
    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }

    public function toSearchableArray(): array
    {
        return [
            'objectID' => "Kanvas\Scribe\SalesReceipts\Models\SalesReceipt::{$this->id}",
            'id' => (string) $this->id,
            'uuid' => (string) $this->uuid,
            'apps_id' => $this->apps_id,
            'companies_id' => $this->companies_id,
            'customer_organization_id' => $this->customer_organization_id,
            'receipt_number' => (string) $this->receipt_number,
            'billable_display_name' => (string) $this->billable_display_name,
            'billable_legal_name' => (string) $this->billable_legal_name,
            'billable_email' => (string) $this->billable_email,
            'billable_tax_id' => (string) $this->billable_tax_id,
            'external_id' => (string) $this->external_id,
            'notes' => (string) $this->notes,
            'status' => $this->status?->value,
            'source' => (string) $this->source,
            'currency' => (string) $this->currency,
            'total_native' => (float) $this->total_native,
            'total_base' => (float) $this->total_base,
            'receipt_date' => $this->receipt_date?->timestamp,
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
                ['name' => 'customer_organization_id', 'type' => 'int64', 'optional' => true, 'facet' => true],
                ['name' => 'receipt_number', 'type' => 'string', 'optional' => true],
                ['name' => 'billable_display_name', 'type' => 'string', 'optional' => true],
                ['name' => 'billable_legal_name', 'type' => 'string', 'optional' => true],
                ['name' => 'billable_email', 'type' => 'string', 'optional' => true],
                ['name' => 'billable_tax_id', 'type' => 'string', 'optional' => true],
                ['name' => 'external_id', 'type' => 'string', 'optional' => true],
                ['name' => 'notes', 'type' => 'string', 'optional' => true],
                ['name' => 'status', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'source', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'currency', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'total_native', 'type' => 'float', 'optional' => true],
                ['name' => 'total_base', 'type' => 'float', 'optional' => true, 'sort' => true],
                ['name' => 'receipt_date', 'type' => 'int64', 'optional' => true, 'sort' => true],
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
                'query_by' => 'receipt_number,billable_display_name,billable_legal_name,billable_email,billable_tax_id,external_id',
            ]);
        }

        return $searchQuery;
    }
}
