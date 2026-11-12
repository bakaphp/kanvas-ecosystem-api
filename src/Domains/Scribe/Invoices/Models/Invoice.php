<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Models;

use Baka\Casts\Json;
use Baka\Contracts\EngageableInterface;
use Baka\Contracts\PayableInterface;
use Baka\Observers\ClearsLightHouseCacheObserver;
use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;
use Kanvas\Approvals\Contracts\DeliversApprovalNoticesInterface;
use Kanvas\Approvals\Contracts\ReactsToApprovalExpiryInterface;
use Kanvas\Approvals\Traits\HasApprovals;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\FollowUp\Traits\HasFollowUpState;
use Kanvas\NervousSystem\Ledger\Models\Event as LedgerEvent;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Kanvas\Scribe\Approvals\Concerns\NotifiesApproversOnSlack;
use Kanvas\Scribe\Approvals\Concerns\ReturnsToDraftOnApprovalExpiry;
use Kanvas\Scribe\Concerns\HasInternalNotes;
use Kanvas\Scribe\Delivery\Concerns\IsEngageableDocument;
use Kanvas\Scribe\Invoices\Actions\AmendInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\MarkInvoicePaidAction;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceAmendment;
use Kanvas\Scribe\Invoices\Enums\AgingBucketEnum;
use Kanvas\Scribe\Invoices\Enums\ConfigurationEnum;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceCollectionStateEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Kanvas\Scribe\Models\BaseModel;
use Kanvas\Scribe\Payments\Models\Payment;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Override;
use Throwable;

/**
 * Scribe.Invoice — AR-side document (invoice OR credit_note via the document_type discriminator).
 *
 * Implements Baka\Contracts\PayableInterface so Souk.Payments can attach via its polymorphic 'payable' morph
 * (payable_type='Kanvas\Scribe\Invoices\Models\Invoice', payable_id=<this>). Pattern set up in PR -1.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property DocumentTypeEnum $document_type
 * @property int|null $customer_organization_id
 * @property string|null $invoice_number
 * @property string|null $billable_display_name
 * @property string|null $billable_legal_name
 * @property string|null $billable_tax_id
 * @property string|null $billable_email
 * @property array|null $billing_address_snapshot
 * @property array|null $shipping_address_snapshot
 * @property InvoiceDocumentStatusEnum $document_status
 * @property InvoiceCollectionStateEnum|null $collection_state
 * @property string $tax_calculation_mode
 * @property string $delivery_status
 * @property Carbon|null $delivery_last_attempt_at
 * @property string|null $delivery_bounce_reason
 * @property Carbon|null $expected_payment_date
 * @property Carbon|null $issued_date
 * @property Carbon|null $due_date
 * @property Carbon|null $sent_at
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
 * @property int|null $parent_invoice_grouping_id
 * @property string|null $notes
 * @property string|null $internal_notes
 * @property string|null $terms
 * @property string $source
 * @property string|null $external_id
 * @property string|null $external_url
 * @property Carbon|null $last_synced_at
 * @property JournalEntryOriginEnum $origin
 * @property int|null $quote_id
 * @property int|null $order_id
 * @property int|null $parent_invoice_id
 * @property array|null $metadata
 * @property bool $is_deleted
 * @property int|null $users_id
 */
#[ObservedBy([ClearsLightHouseCacheObserver::class])]
class Invoice extends BaseModel implements PayableInterface, DeliversApprovalNoticesInterface, ReactsToApprovalExpiryInterface, EngageableInterface
{
    use HasApprovals;
    use HasInternalNotes;
    use NotifiesApproversOnSlack;
    use ReturnsToDraftOnApprovalExpiry;
    use CanUseWorkflow;
    use DynamicSearchableTrait {
        search as public traitSearch;
    }
    use EmitsLedgerEventsForEntity;
    use HasFollowUpState;
    use HasLightHouseCache;
    use IsEngageableDocument;
    use UuidTrait;

    protected $table = 'invoices';
    protected $guarded = [];

    protected $casts = [
        'document_type' => DocumentTypeEnum::class,
        'document_status' => InvoiceDocumentStatusEnum::class,
        'collection_state' => InvoiceCollectionStateEnum::class,
        'origin' => JournalEntryOriginEnum::class,
        'is_deleted' => 'boolean',
        'expected_payment_date' => 'date',
        'issued_date' => 'date',
        'due_date' => 'date',
        'sent_at' => 'datetime',
        'voided_at' => 'datetime',
        'paid_at' => 'datetime',
        'fx_rate_at' => 'datetime',
        'delivery_last_attempt_at' => 'datetime',
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
        'billing_address_snapshot' => Json::class,
        'shipping_address_snapshot' => Json::class,
        'tax_metadata' => Json::class,
        'regional_compliance' => Json::class,
        'metadata' => Json::class,
    ];

    public function scopeOpen(Builder $query): Builder
    {
        return $query
            ->whereIn('document_status', [
                InvoiceDocumentStatusEnum::ISSUED->value,
                InvoiceDocumentStatusEnum::SENT->value,
            ])
            ->where('balance_due_native', '>', 0);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class, 'invoice_id', 'id')->orderBy('sort_order');
    }

    public function taxLines(): HasMany
    {
        return $this->hasMany(InvoiceTaxLine::class, 'invoice_id', 'id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(InvoicePaymentAllocation::class, 'invoice_id', 'id');
    }

    public function parentInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_invoice_id', 'id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'parent_invoice_id', 'id')
            ->where('document_type', DocumentTypeEnum::CREDIT_NOTE->value);
    }

    public function debitNotes(): HasMany
    {
        return $this->hasMany(self::class, 'parent_invoice_id', 'id')
            ->where('document_type', DocumentTypeEnum::DEBIT_NOTE->value);
    }

    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(
            Payment::class,
            InvoicePaymentAllocation::class,
            'invoice_id',
            'id',
            'id',
            'payment_id',
        );
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
        return $this->document_status === InvoiceDocumentStatusEnum::PAID;
    }

    #[Override]
    public function markAsPaid(UserInterface $user): void
    {
        new MarkInvoicePaidAction(invoice: $this, user: $user)->execute();
    }

    public function computeAgingBucket(?Carbon $today = null): AgingBucketEnum
    {
        return AgingBucketEnum::forInvoice($this->due_date, $today ?? Carbon::today());
    }

    public function isCreditNote(): bool
    {
        return $this->document_type === DocumentTypeEnum::CREDIT_NOTE;
    }

    public function isDebitNote(): bool
    {
        return $this->document_type === DocumentTypeEnum::DEBIT_NOTE;
    }

    /**
     * Every AmendInvoiceAction call that changed something, newest first. The record is the
     * `scribe.invoice.amended` ledger event (one per amendment, its own diff only); `metadata.amendments`
     * is read only for invoices amended before that, and a malformed legacy entry is skipped.
     *
     * @return list<InvoiceAmendment>
     */
    public function amendmentHistory(): array
    {
        $fromLedger = LedgerEvent::query()
            ->where('apps_id', $this->apps_id)
            ->where('companies_id', $this->companies_id)
            ->where('source_entity_type', self::class)
            ->where('source_entity_id', $this->getId())
            ->where('event_type', AmendInvoiceAction::LEDGER_EVENT)
            ->orderByDesc('id')
            ->get()
            ->map(fn (LedgerEvent $event): ?InvoiceAmendment => $this->toAmendment([
                ...(array) $event->payload,
                'amended_at' => $event->occurred_at?->toIso8601String(),
            ]))
            ->filter()
            ->values()
            ->all();

        $legacy = $this->metadata['amendments'] ?? [];
        $fromMetadata = is_array($legacy)
            ? array_values(array_filter(array_map(
                fn (mixed $entry): ?InvoiceAmendment => is_array($entry) ? $this->toAmendment($entry) : null,
                array_reverse($legacy),
            )))
            : [];

        return [...$fromLedger, ...$fromMetadata];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function toAmendment(array $entry): ?InvoiceAmendment
    {
        try {
            return new InvoiceAmendment(
                amended_at: Carbon::parse((string) ($entry['amended_at'] ?? '')),
                amended_by_users_id: isset($entry['amended_by_users_id']) ? (int) $entry['amended_by_users_id'] : null,
                reason: (string) ($entry['reason'] ?? ''),
                changes: is_array($entry['changes'] ?? null) ? $entry['changes'] : [],
            );
        } catch (Throwable) {
            return null;
        }
    }

    public function hasBeenAmended(): bool
    {
        return $this->amendmentHistory() !== [];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'customer_organization_id', 'id');
    }

    /**
     * The Souk order this invoice bills (B2B: one order, N invoices). Cross-connection, so never
     * `whereHas('order')` — it is silently empty; filter on `order_id` instead.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    #[Override]
    public function getGraphTypeName(): string
    {
        return 'ScribeInvoice';
    }

    protected function approvalNoun(): string
    {
        return 'invoice';
    }

    /**
     * AR has its own Slack-connected agent; unset falls back to the AP notifier.
     */
    protected function approvalNotifierSettingKey(): string
    {
        return ConfigurationEnum::AR_SLACK_NOTIFIER_AGENT_ID->value;
    }

    protected function approvalNoticeBody(): string
    {
        $customer = $this->billable_display_name ?? $this->customer?->name ?? 'Unknown customer';

        return "You have an AR invoice pending approval:\nCustomer: {$customer}\nAmount: {$this->currency} "
            . number_format((float) $this->total_native, 2)
            . ($this->notes !== null && $this->notes !== '' ? "\nMemo: {$this->notes}" : '')
            . "\nInvoice ID (Kanvas): {$this->getKey()}";
    }

    protected function sourceDomainForLedger(): string
    {
        return 'Scribe';
    }

    public function searchableAs(): string
    {
        $model = ! $this->searchableDeleteRecord() ? $this : $this->withTrashed()->find($this->id);
        $app = $model->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_scribe_invoice_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'scribe_invoice_index');
    }

    #[Override]
    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }

    public function toSearchableArray(): array
    {
        return [
            'objectID' => self::class . "::{$this->id}",
            'id' => (string) $this->id,
            'uuid' => (string) $this->uuid,
            'apps_id' => $this->apps_id,
            'companies_id' => $this->companies_id,
            'users_id' => $this->users_id,
            'customer_organization_id' => $this->customer_organization_id,
            'quote_id' => $this->quote_id,
            'invoice_number' => (string) $this->invoice_number,
            'billable_display_name' => (string) $this->billable_display_name,
            'billable_legal_name' => (string) $this->billable_legal_name,
            'billable_email' => (string) $this->billable_email,
            'billable_tax_id' => (string) $this->billable_tax_id,
            'external_id' => (string) $this->external_id,
            'notes' => (string) $this->notes,
            'document_type' => $this->document_type?->value,
            'document_status' => $this->document_status?->value,
            'collection_state' => $this->collection_state?->value,
            'source' => (string) $this->source,
            'currency' => (string) $this->currency,
            'total_native' => (float) $this->total_native,
            'total_base' => (float) $this->total_base,
            'balance_due_base' => (float) $this->balance_due_base,
            'issued_date' => $this->issued_date?->timestamp,
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
                ['name' => 'customer_organization_id', 'type' => 'int64', 'optional' => true, 'facet' => true],
                ['name' => 'quote_id', 'type' => 'int64', 'optional' => true],
                ['name' => 'invoice_number', 'type' => 'string', 'optional' => true],
                ['name' => 'billable_display_name', 'type' => 'string', 'optional' => true],
                ['name' => 'billable_legal_name', 'type' => 'string', 'optional' => true],
                ['name' => 'billable_email', 'type' => 'string', 'optional' => true],
                ['name' => 'billable_tax_id', 'type' => 'string', 'optional' => true],
                ['name' => 'external_id', 'type' => 'string', 'optional' => true],
                ['name' => 'notes', 'type' => 'string', 'optional' => true],
                ['name' => 'document_type', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'document_status', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'collection_state', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'source', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'currency', 'type' => 'string', 'optional' => true, 'facet' => true],
                ['name' => 'total_native', 'type' => 'float', 'optional' => true],
                ['name' => 'total_base', 'type' => 'float', 'optional' => true, 'sort' => true],
                ['name' => 'balance_due_base', 'type' => 'float', 'optional' => true, 'sort' => true],
                ['name' => 'issued_date', 'type' => 'int64', 'optional' => true, 'sort' => true],
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
                'query_by' => 'invoice_number,billable_display_name,billable_legal_name,billable_email,billable_tax_id,external_id',
            ]);
        }

        return $searchQuery;
    }
}
