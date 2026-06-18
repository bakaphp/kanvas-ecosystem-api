<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Scribe\TaxCodes\Models\TaxCode;

/**
 * Structured tax row on an invoice. One row per applied tax (ITBIS 18%, ISR 10%, etc.).
 *
 * @property int $id
 * @property int $invoice_id
 * @property int|null $tax_code_id
 * @property string $name
 * @property float $tax_rate
 * @property string|null $jurisdiction
 * @property float $tax_amount_native
 * @property float $tax_amount_base
 * @property array|null $metadata
 */
class InvoiceTaxLine extends EloquentModel
{
    protected $connection = 'accounting';
    protected $table = 'invoice_tax_lines';
    protected $guarded = [];

    protected $casts = [
        'tax_rate' => 'float',
        'tax_amount_native' => 'float',
        'tax_amount_base' => 'float',
        'metadata' => Json::class,
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id', 'id');
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'tax_code_id', 'id');
    }
}
