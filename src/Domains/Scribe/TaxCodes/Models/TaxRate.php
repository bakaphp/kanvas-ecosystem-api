<?php

declare(strict_types=1);

namespace Kanvas\Scribe\TaxCodes\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Scribe\Ledger\Models\Account;

/**
 * @property int $id
 * @property int $tax_code_id
 * @property string $name
 * @property float $rate
 * @property int|null $tax_account_id
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon $effective_from
 * @property \Illuminate\Support\Carbon|null $effective_to
 * @property array|null $metadata
 */
class TaxRate extends EloquentModel
{
    protected $connection = 'accounting';
    protected $table = 'tax_rates';
    protected $guarded = [];

    protected $casts = [
        'rate' => 'float',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'metadata' => Json::class,
    ];

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'tax_code_id', 'id');
    }

    public function taxAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'tax_account_id', 'id');
    }
}
