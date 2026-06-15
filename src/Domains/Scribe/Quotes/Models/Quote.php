<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Quotes\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Kanvas\Scribe\Models\BaseModel;
use Kanvas\Scribe\Quotes\Enums\QuoteStatusEnum;

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
 * @property \Illuminate\Support\Carbon|null $issued_date
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $valid_until
 * @property \Illuminate\Support\Carbon|null $accepted_at
 * @property \Illuminate\Support\Carbon|null $rejected_at
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

    /**
     * The human contact at the customer Organization the quote was addressed to. Lives in the
     * `crm` connection — Eloquent's BelongsTo handles the cross-connection lookup. Optional.
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(People::class, 'contact_people_id', 'id');
    }

    /**
     * The Guild Organization that is the legal customer this quote was issued to. Phase 4 —
     * direct FK, no polymorphism.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'customer_organization_id', 'id');
    }
}
