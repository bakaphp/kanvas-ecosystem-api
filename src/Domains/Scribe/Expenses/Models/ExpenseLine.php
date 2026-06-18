<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Expenses\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Scribe\Items\Models\Item;
use Kanvas\Scribe\Ledger\Models\Account;

/**
 * @property int $id
 * @property int $expense_id
 * @property int $sort_order
 * @property int|null $item_id
 * @property string|null $description
 * @property float $amount_native
 * @property float $amount_base
 * @property float $tax_amount_native
 * @property float $tax_amount_base
 * @property int|null $expense_account_id
 * @property int|null $class_id
 * @property int|null $department_id
 * @property array|null $metadata
 */
class ExpenseLine extends EloquentModel
{
    protected $connection = 'accounting';
    protected $table = 'expense_lines';
    protected $guarded = [];

    protected $casts = [
        'amount_native' => 'float',
        'amount_base' => 'float',
        'tax_amount_native' => 'float',
        'tax_amount_base' => 'float',
        'metadata' => Json::class,
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'expense_id', 'id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id', 'id');
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id', 'id');
    }
}
