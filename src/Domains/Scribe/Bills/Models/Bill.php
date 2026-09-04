<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Models;

use Baka\Casts\Json;
use Baka\Contracts\PayableInterface;
use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;
use Kanvas\Approvals\Traits\HasApprovals;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Kanvas\Scribe\Bills\Actions\MarkBillPaidAction;
use Kanvas\Scribe\Bills\Enums\BillCollectionStateEnum;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Enums\PaymentStatusHintEnum;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Kanvas\Scribe\Models\BaseModel;
use Kanvas\Scribe\Payments\Models\Payment;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Override;

/**
 * Scribe.Bill — AP-side document (vendor invoice we owe).
 *
 * Mirrors Invoice but flipped to vendor (Payee) side. Implements Baka\Contracts\PayableInterface so
 * Souk.Payments can attach via the polymorphic 'payable' morph for outbound payment runs.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property int|null $vendor_organization_id
 * @property string|null $bill_number
 * @property string|null $vendor_display_name
 * @property string|null $vendor_legal_name
 * @property string|null $vendor_tax_id
 * @property string|null $vendor_email
 * @property array|null $vendor_address_snapshot
 * @property BillDocumentStatusEnum $document_status
 * @property PaymentStatusHintEnum $payment_status_hint
 * @property BillCollectionStateEnum|null $collection_state
 * @property string $tax_calculation_mode
 * @property Carbon|null $bill_date
 * @property Carbon|null $received_date
 * @property Carbon|null $due_date
 * @property Carbon|null $scheduled_payment_date
 * @property int $net_terms_days
 * @property Carbon|null $voided_at
 * @property string|null $void_reason_code
 * @property string $currency
 * @property float $fx_rate_to_base
 * @property Carbon|null $fx_rate_at
 * @property float $subtotal_native
 * @property float $tax_native
 * @property float $discount_native
 * @property float $total_native
 * @property float $paid_native
 * @property float $balance_due_native
 * @property float $subtotal_base
 * @property float $tax_base
 * @property float $discount_base
 * @property float $total_base
 * @property float $paid_base
 * @property float $balance_due_base
 * @property Carbon|null $paid_at
 * @property array|null $tax_metadata
 * @property array|null $regional_compliance
 * @property string|null $notes
 * @property string|null $internal_notes
 * @property string|null $terms
 * @property string $source
 * @property string|null $external_id
 * @property string|null $external_url
 * @property Carbon|null $last_synced_at
 * @property JournalEntryOriginEnum $origin
 * @property int|null $purchase_order_id
 * @property int|null $pdf_ingest_log_id
 * @property array|null $metadata
 * @property bool $is_deleted
 * @property int|null $users_id
 */
class Bill extends BaseModel implements PayableInterface
{
    use HasApprovals;
    use CanUseWorkflow;
    use DynamicSearchableTrait {
        search as public traitSearch;
    }
    use EmitsLedgerEventsForEntity;
    use UuidTrait;

    protected $table = 'bills';
    protected $guarded = [];

    protected $casts = [
        'document_status' => BillDocumentStatusEnum::class,
        'payment_status_hint' => PaymentStatusHintEnum::class,
        'collection_state' => BillCollectionStateEnum::class,
        'origin' => JournalEntryOriginEnum::class,
        'is_deleted' => 'boolean',
        'bill_date' => 'date',
        'received_date' => 'date',
        'due_date' => 'date',
        'scheduled_payment_date' => 'date',
        'voided_at' => 'datetime',
        'paid_at' => 'datetime',
        'fx_rate_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'fx_rate_to_base' => 'float',
        'subtotal_native' => 'float',
        'tax_native' => 'float',
        'discount_native' => 'float',
        'total_native' => 'float',
        'paid_native' => 'float',
        'balance_due_native' => 'float',
        'subtotal_base' => 'float',
        'tax_base' => 'float',
        'discount_base' => 'float',
        'total_base' => 'float',
        'paid_base' => 'float',
        'balance_due_base' => 'float',
        'vendor_address_snapshot' => Json::class,
        'tax_metadata' => Json::class,
        'regional_compliance' => Json::class,
        'metadata' => Json::class,
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class, 'bill_id', 'id')->orderBy('sort_order');
    }

    public function taxLines(): HasMany
    {
        return $this->hasMany(BillTaxLine::class, 'bill_id', 'id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(BillPaymentAllocation::class, 'bill_id', 'id');
    }

    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(
            Payment::class,
            BillPaymentAllocation::class,
            'bill_id',
            'id',
            'id',
            'payment_id',
        );
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'vendor_organization_id', 'id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(BillReceipt::class, 'bill_id', 'id');
    }

    #[Override]
    public function getPayableTotalNative(): float
    {
        return (float) ($this->total_native ?? 0);
    }

    #[Override]
    public function getPayableBalanceDueNative(): float
    {
        return (float) ($this->balance_due_native ?? 0);
    }

    #[Override]
    public function getPayableCurrency(): string
    {
        return (string) ($this->currency ?? 'USD');
    }

    #[Override]
    public function isPaid(): bool
    {
        return $this->document_status === BillDocumentStatusEnum::PAID;
    }

    #[Override]
    public function markAsPaid(UserInterface $user): void
    {
        new MarkBillPaidAction(bill: $this, user: $user)->execute();
    }

    protected function sourceDomainForLedger(): string
    {
        return 'Scribe';
    }

    public function searchableAs(): string
    {
        $model = ! $this->searchableDeleteRecord() ? $this : $this->withTrashed()->find($this->id);
        $app = $model->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_scribe_bill_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'scribe_bill_index');
    }

    #[Override]
    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }

    public function toSearchableArray(): array
    {
        return [
            'objectID' => "Kanvas\Scribe\Bills\Models\Bill::{$this->id}",
            'id' => (string) $this->id,
            'uuid' => (string) $this->uuid,
            'apps_id' => $this->apps_id,
            'companies_id' => $this->companies_id,
            'users_id' => $this->users_id,
            'vendor_organization_id' => $this->vendor_organization_id,
            'purchase_order_id' => $this->purchase_order_id,
            'bill_number' => (string) $this->bill_number,
            'vendor_display_name' => (string) $this->vendor_display_name,
            'vendor_legal_name' => (string) $this->vendor_legal_name,
            'vendor_email' => (string) $this->vendor_email,
            'vendor_tax_id' => (string) $this->vendor_tax_id,
            'external_id' => (string) $this->external_id,
            'notes' => (string) $this->notes,
            'document_status' => $this->document_status?->value,
            'payment_status_hint' => $this->payment_status_hint?->value,
            'collection_state' => $this->collection_state?->value,
            'source' => (string) $this->source,
            'currency' => (string) $this->currency,
            'total_native' => (float) $this->total_native,
            'total_base' => (float) $this->total_base,
            'balance_due_base' => (float) $this->balance_due_base,
            'bill_date' => $this->bill_date?->timestamp,
            'due_date' => $this->due_date?->timestamp,
            'paid_at' => $this->paid_at?->timestamp,
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
                ['name' => 'purchase_order_id', 'type' => 'int64', 'optional' => true],
                ['name' => 'bill_number', 'type' => 'string', 'optional' => true],
                ['name' => 'vendor_display_name', 'type' => 'string', 'optional' => true],
                ['name' => 'vendor_legal_name', 'type' => 'string', 'optional' => true],
                ['name' => 'vendor_email', 'type' => 'string', 'optional' => true],
                ['name' => 'vendor_tax_id', 'type' => 'string', 'optional' => true],
                ['name' => 'external_id', 'type' => 'string', 'optional' => true],
                ['name' => 'notes', 'type' => 'string', 'optional' => true],
                ['name' => 'document_status', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'payment_status_hint', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'collection_state', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'source', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'currency', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'total_native', 'type' => 'float', 'optional' => true],
                ['name' => 'total_base', 'type' => 'float', 'optional' => true, 'sort' => true],
                ['name' => 'balance_due_base', 'type' => 'float', 'optional' => true, 'sort' => true],
                ['name' => 'bill_date', 'type' => 'int64', 'optional' => true, 'sort' => true],
                ['name' => 'due_date', 'type' => 'int64', 'optional' => true, 'sort' => true],
                ['name' => 'paid_at', 'type' => 'int64', 'optional' => true],
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
                'query_by' => 'bill_number,vendor_display_name,vendor_legal_name,vendor_email,vendor_tax_id,external_id',
            ]);
        }

        return $searchQuery;
    }
}
