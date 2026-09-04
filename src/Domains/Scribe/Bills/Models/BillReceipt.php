<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Users\Models\Users;

/**
 * Pointer to a Kanvas Filesystem row (cross-DB; no DDL FK). Mirrors Expense\Models\ExpenseReceipt.
 *
 * @property int $id
 * @property int $bill_id
 * @property int $filesystem_id
 * @property Carbon $uploaded_at
 * @property int|null $uploaded_by_users_id
 * @property array|null $metadata
 */
class BillReceipt extends EloquentModel
{
    protected $connection = 'accounting';
    protected $table = 'bill_receipts';
    protected $guarded = [];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'metadata' => Json::class,
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'bill_id', 'id');
    }

    public function filesystem(): BelongsTo
    {
        return $this->belongsTo(Filesystem::class, 'filesystem_id', 'id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'uploaded_by_users_id', 'id');
    }
}
