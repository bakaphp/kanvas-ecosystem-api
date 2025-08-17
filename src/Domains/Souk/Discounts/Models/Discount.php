<?php

declare(strict_types=1);

namespace Kanvas\Souk\Discounts\Models;

use Baka\Traits\UuidTrait;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Souk\Discounts\Observers\DiscountObserver;
use Kanvas\Souk\Models\BaseModel;

/**
 * Class Discount
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $uuid
 * @property string $name
 * @property string|null $description
 * @property int $discount_type_id
 * @property float $value
 * @property bool $is_percentage
 * @property float|null $min_order_value
 * @property float|null $max_discount_amount
 * @property string|null $code
 * @property \Illuminate\Support\Carbon|null $start_date
 * @property \Illuminate\Support\Carbon|null $end_date
 * @property bool $is_active
 * @property int|null $usage_limit
 * @property int $usage_count
 * @property bool $is_one_per_customer
 * @property bool $is_deleted
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
#[ObservedBy(DiscountObserver::class)]
class Discount extends BaseModel
{
    use UuidTrait;

    protected $table = 'discounts';
    protected $guarded = [];

    protected $casts = [
        'value' => 'float',
        'min_order_value' => 'float',
        'max_discount_amount' => 'float',
        'is_percentage' => 'boolean',
        'is_active' => 'boolean',
        'is_one_per_customer' => 'boolean',
        'is_deleted' => 'boolean',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
    ];

    public function discountType(): BelongsTo
    {
        return $this->belongsTo(DiscountType::class, 'discount_type_id');
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(DiscountCondition::class, 'discount_id');
    }

    public function orderDiscounts(): HasMany
    {
        return $this->hasMany(OrderDiscount::class, 'discount_id');
    }

    public function orderItemDiscounts(): HasMany
    {
        return $this->hasMany(OrderItemDiscount::class, 'discount_id');
    }

    /**
     * Check if the discount is valid for the given date
     */
    public function isValidForDate(?DateTimeInterface $date = null): bool
    {
        $date = $date ?? now();

        if ($this->start_date && $date < $this->start_date) {
            return false;
        }

        if ($this->end_date && $date > $this->end_date) {
            return false;
        }

        return true;
    }

    /**
     * Check if the discount can be used
     */
    public function canBeUsed(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if (! $this->isValidForDate()) {
            return false;
        }

        if ($this->usage_limit !== null && $this->usage_count >= $this->usage_limit) {
            return false;
        }

        return true;
    }

    public function isOverUsageLimit(): bool
    {
        return $this->usage_limit !== null && $this->usage_count >= $this->usage_limit;
    }

    /**
     * Increment the usage count
     */
    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }

    /**
     * Calculate the discount amount for a given order value
     */
    public function calculateDiscountAmount(float $orderValue): float
    {
        if ($this->min_order_value !== null && $orderValue < $this->min_order_value) {
            return 0;
        }

        $discountAmount = $this->is_percentage
            ? ($orderValue * $this->value / 100.0)
            : $this->value;

        if ($this->max_discount_amount !== null && $discountAmount > $this->max_discount_amount) {
            $discountAmount = $this->max_discount_amount;
        }

        return min($discountAmount, $orderValue);
    }
}
