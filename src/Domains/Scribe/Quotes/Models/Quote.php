<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Quotes\Models;

use Baka\Casts\Json;
use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Kanvas\Scribe\Models\BaseModel;
use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;
use Override;

/**
 * Scribe.Quote — pre-economic-event sales proposal.
 *
 * No JE posts (quotes don't move the books — see plan §11.1). Lifecycle:
 *   draft → sent → accepted | rejected | expired
 *   accepted → converted   (set when ConvertQuoteToInvoiceAction fires; converted_to_invoice_id stamped)
 *
 * Revision chain: when a customer asks for a changed version, CreateQuoteRevisionAction creates a new Quote
 * with parent_quote_id pointing at the original and increments revision_number. The parent is moved to
 * SUPERSEDED so it's clear the offer that's "live" is the latest revision.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property int|null $customer_organization_id
 * @property int|null $contact_people_id
 * @property string|null $quote_number
 * @property string|null $billable_display_name
 * @property string|null $billable_legal_name
 * @property string|null $billable_tax_id
 * @property string|null $billable_email
 * @property array|null $billing_address_snapshot
 * @property QuoteStatusEnum $status
 * @property Carbon|null $issued_date
 * @property Carbon|null $sent_at
 * @property Carbon|null $valid_until
 * @property Carbon|null $accepted_at
 * @property Carbon|null $rejected_at
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
 * @property array|null $regional_compliance
 * @property string|null $notes
 * @property string|null $internal_notes
 * @property string|null $terms
 * @property string $source
 * @property string|null $external_id
 * @property string|null $external_url
 * @property JournalEntryOriginEnum $origin
 * @property int|null $parent_quote_id
 * @property int $revision_number
 * @property array|null $metadata
 * @property bool $is_deleted
 * @property int|null $users_id
 */
class Quote extends BaseModel
{
    use DynamicSearchableTrait {
        search as public traitSearch;
    }
    use UuidTrait;

    protected $table = 'quotes';
    protected $guarded = [];

    protected $casts = [
        'status' => QuoteStatusEnum::class,
        'origin' => JournalEntryOriginEnum::class,
        'is_deleted' => 'boolean',
        'issued_date' => 'date',
        'valid_until' => 'date',
        'sent_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
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
        'regional_compliance' => Json::class,
        'metadata' => Json::class,
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class, 'quote_id', 'id')->orderBy('sort_order');
    }

    public function parentQuote(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_quote_id', 'id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_quote_id', 'id');
    }

    public function convertedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'converted_to_invoice_id', 'id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(People::class, 'contact_people_id', 'id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'customer_organization_id', 'id');
    }

    public function searchableAs(): string
    {
        $model = ! $this->searchableDeleteRecord() ? $this : $this->withTrashed()->find($this->id);
        $app = $model->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_scribe_quote_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'scribe_quote_index');
    }

    #[Override]
    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }

    public function toSearchableArray(): array
    {
        return [
            'objectID' => "Kanvas\Scribe\Quotes\Models\Quote::{$this->id}",
            'id' => (string) $this->id,
            'uuid' => (string) $this->uuid,
            'apps_id' => $this->apps_id,
            'companies_id' => $this->companies_id,
            'users_id' => $this->users_id,
            'customer_organization_id' => $this->customer_organization_id,
            'contact_people_id' => $this->contact_people_id,
            'parent_quote_id' => $this->parent_quote_id,
            'quote_number' => (string) $this->quote_number,
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
            'issued_date' => $this->issued_date?->timestamp,
            'valid_until' => $this->valid_until?->timestamp,
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
                ['name' => 'contact_people_id', 'type' => 'int64', 'optional' => true],
                ['name' => 'parent_quote_id', 'type' => 'int64', 'optional' => true],
                ['name' => 'quote_number', 'type' => 'string', 'optional' => true],
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
                ['name' => 'issued_date', 'type' => 'int64', 'optional' => true, 'sort' => true],
                ['name' => 'valid_until', 'type' => 'int64', 'optional' => true, 'sort' => true],
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
                'query_by' => 'quote_number,billable_display_name,billable_legal_name,billable_email,billable_tax_id,external_id',
            ]);
        }

        return $searchQuery;
    }
}
