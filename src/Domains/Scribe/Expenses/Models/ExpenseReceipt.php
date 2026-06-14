<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Expenses\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pointer to a Kanvas Filesystem row (cross-DB; no DDL FK).
 *
 * @property int $id
 * @property int $expense_id
 * @property int $filesystem_id
 * @property \Illuminate\Support\Carbon $uploaded_at
 * @property int|null $uploaded_by_users_id
 * @property array|null $metadata
 */
class ExpenseReceipt extends EloquentModel
{
    protected $connection = 'accounting';
    protected $table = 'expense_receipts';
    protected $guarded = [];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'metadata' => Json::class,
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'expense_id', 'id');
    }
}
