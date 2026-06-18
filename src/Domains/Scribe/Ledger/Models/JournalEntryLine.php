<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NOT extending Scribe\Models\BaseModel because journal_entry_lines is intentionally lean:
 *   - no apps_id / companies_id (inherited from the parent journal_entries row)
 *   - no soft-delete (lines are immutable once posted; reversals are mirror-JE rows)
 *   - no custom fields / files / lifecycle traits
 *
 * Uses the `accounting` connection directly via $connection.
 *
 * @property int $id
 * @property int $journal_entry_id
 * @property int $account_id
 * @property int $sort_order
 * @property float $debit_native
 * @property float $credit_native
 * @property float $debit_base
 * @property float $credit_base
 * @property string $currency
 * @property float $fx_rate_to_base
 * @property string|null $customer_billable_type
 * @property int|null $customer_billable_id
 * @property string|null $vendor_billable_type
 * @property int|null $vendor_billable_id
 * @property int|null $item_id
 * @property int|null $class_id
 * @property int|null $department_id
 * @property string|null $memo
 * @property array|null $metadata
 */
class JournalEntryLine extends EloquentModel
{
    protected $connection = 'accounting';
    protected $table = 'journal_entry_lines';
    protected $guarded = [];

    protected $casts = [
        'debit_native' => 'float',
        'credit_native' => 'float',
        'debit_base' => 'float',
        'credit_base' => 'float',
        'fx_rate_to_base' => 'float',
        'metadata' => Json::class,
    ];

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id', 'id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id', 'id');
    }

    public function isDebit(): bool
    {
        return $this->debit_native > 0;
    }

    public function isCredit(): bool
    {
        return $this->credit_native > 0;
    }
}
