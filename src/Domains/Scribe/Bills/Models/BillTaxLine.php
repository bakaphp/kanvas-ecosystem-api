<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Scribe\TaxCodes\Models\TaxCode;

/**
 * @property int $id
 * @property int $bill_id
 * @property int|null $tax_code_id
 * @property string $name
 * @property float|null $tax_rate
 * @property string|null $jurisdiction
 * @property float $tax_amount_native
 * @property float $tax_amount_base
 * @property array|null $metadata
 */
class BillTaxLine extends EloquentModel
{
    protected $connection = 'accounting';
    protected $table = 'bill_tax_lines';
    protected $guarded = [];

    protected $casts = [
        'tax_rate' => 'float',
        'tax_amount_native' => 'float',
        'tax_amount_base' => 'float',
        'metadata' => Json::class,
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'bill_id', 'id');
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'tax_code_id', 'id');
    }
}
