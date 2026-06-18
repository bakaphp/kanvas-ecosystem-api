<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesReceipts\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\NervousSystem\Ledger\Traits\EmitsLedgerEventsForEntity;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Models\BaseModel;
use Kanvas\Scribe\SalesReceipts\Enums\SalesReceiptStatusEnum;

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
}
