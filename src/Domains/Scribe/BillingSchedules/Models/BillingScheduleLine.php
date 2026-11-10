<?php

declare(strict_types=1);

namespace Kanvas\Scribe\BillingSchedules\Models;

use Baka\Support\Money;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Scribe\Items\Models\Item;
use Kanvas\Scribe\Models\BaseModel;

/**
 * One line every invoice of the schedule bills, in the schedule's currency.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $billing_schedule_id
 * @property int $sort_order
 * @property string $description
 * @property string $quantity
 * @property string $unit_price
 * @property string|null $tax_rate
 * @property string $discount_amount
 * @property string $tax_amount
 * @property int|null $item_id
 */
class BillingScheduleLine extends BaseModel
{
    protected $table = 'billing_schedule_lines';
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'discount_amount' => 'decimal:4',
        'tax_rate' => 'decimal:6',
        'tax_amount' => 'decimal:4',
        'item_id' => 'integer',
        'sort_order' => 'integer',
        'is_deleted' => 'boolean',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(BillingSchedule::class, 'billing_schedule_id', 'id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id', 'id');
    }

    public function total(): BigDecimal
    {
        return Money::of($this->quantity)
            ->multipliedBy(Money::of($this->unit_price))
            ->minus(Money::of($this->discount_amount ?? 0))
            ->plus(Money::of($this->tax_amount));
    }

    /**
     * @return array{description: string, quantity: float, unit_price: float, discount_amount: float, tax_rate: float|null, tax_amount: float, item_id: int|null}
     */
    public function toPresentation(): array
    {
        return [
            'description' => $this->description,
            'quantity' => (float) $this->quantity,
            'unit_price' => (float) $this->unit_price,
            'discount_amount' => (float) $this->discount_amount,
            'tax_rate' => $this->tax_rate !== null ? (float) $this->tax_rate : null,
            'tax_amount' => (float) $this->tax_amount,
            'item_id' => $this->item_id,
        ];
    }
}
