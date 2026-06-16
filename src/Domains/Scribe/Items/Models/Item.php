<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Items\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Scribe\Items\Enums\ItemTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Models\BaseModel;
use Kanvas\Scribe\TaxCodes\Models\TaxCode;

/**
 * Scribe.Items is the canonical catalog of "things you can put on an invoice/bill/quote line."
 *
 * Optional link to inventory.variants (NOT inventory.products — see plan §5 Items↔Variants section):
 *   you sell the variant ("T-shirt Large Red"), not the abstract product ("T-shirt").
 *   NULL for accounting-only items (services, billable hours, retainers, one-off charges).
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property string $item_number
 * @property string $name
 * @property string|null $description
 * @property string $type
 * @property int|null $inventory_variant_id
 * @property int|null $default_income_account_id
 * @property int|null $default_expense_account_id
 * @property int|null $default_tax_code_id
 * @property float|null $default_price_native
 * @property string|null $currency
 * @property bool $is_active
 * @property string $source
 * @property string|null $external_id
 * @property Carbon|null $last_synced_at
 * @property array|null $metadata
 * @property bool $is_deleted
 * @property int|null $users_id
 */
class Item extends BaseModel
{
    use UuidTrait;

    protected $table = 'items';
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'is_deleted' => 'boolean',
        'default_price_native' => 'float',
        'last_synced_at' => 'datetime',
        'metadata' => Json::class,
        'type' => ItemTypeEnum::class,
    ];

    /**
     * Optional link to the canonical Inventory.Variant (the SKU/stock-bearing unit in Souk).
     * Cross-DB BelongsTo — no DDL FK; Eloquent transparently queries on Inventory's `inventory` connection.
     * NULL for accounting-only items (services, billable hours, retainers, one-off charges).
     *
     * @see plan §5 "Items ↔ Inventory.Variants" — Accounting owns the catalog; link is to Variant (not Product)
     */
    public function inventoryVariant(): BelongsTo
    {
        return $this->belongsTo(Variants::class, 'inventory_variant_id', 'id');
    }

    public function defaultIncomeAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_income_account_id', 'id');
    }

    public function defaultExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_expense_account_id', 'id');
    }

    public function defaultTaxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'default_tax_code_id', 'id');
    }
}
