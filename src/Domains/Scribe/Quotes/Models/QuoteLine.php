<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Quotes\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Scribe\Items\Models\Item;

/**
 * @property int $id
 * @property int $quote_id
 * @property int $sort_order
 * @property int|null $item_id
 * @property string|null $sku
 * @property string|null $description
 * @property float $quantity
 * @property float $unit_price_native
 * @property float $discount_amount_native
 * @property float $tax_amount_native
 * @property float $line_total_native
 * @property float $unit_price_base
 * @property float $discount_amount_base
 * @property float $tax_amount_base
 * @property float $line_total_base
 * @property float|null $discount_rate
 * @property float|null $tax_rate
 * @property array|null $tax_metadata
 * @property int|null $class_id
 * @property int|null $department_id
 * @property array|null $metadata
 */
class QuoteLine extends EloquentModel
{
    protected $connection = 'accounting';
    protected $table = 'quote_lines';
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'float',
        'unit_price_native' => 'float',
        'discount_amount_native' => 'float',
        'tax_amount_native' => 'float',
        'line_total_native' => 'float',
        'unit_price_base' => 'float',
        'discount_amount_base' => 'float',
        'tax_amount_base' => 'float',
        'line_total_base' => 'float',
        'discount_rate' => 'float',
        'tax_rate' => 'float',
        'tax_metadata' => Json::class,
        'metadata' => Json::class,
    ];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'quote_id', 'id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id', 'id');
    }
}
