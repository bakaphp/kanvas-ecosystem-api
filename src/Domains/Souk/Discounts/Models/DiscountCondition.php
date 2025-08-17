<?php

declare(strict_types=1);

namespace Kanvas\Souk\Discounts\Models;

use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Souk\Models\BaseModel;

/**
 * Class DiscountCondition
 *
 * @property int $id
 * @property int $apps_id
 * @property int $discount_id
 * @property string $type
 * @property string $operator
 * @property bool $is_deleted
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class DiscountCondition extends BaseModel
{
    use NoCompanyRelationshipTrait;

    protected $table = 'discount_conditions';
    protected $guarded = [];

    protected $casts = [
        'is_deleted' => 'boolean',
    ];

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class, 'discount_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(DiscountConditionValue::class, 'condition_id');
    }

    /**
     * Check if the given value matches the condition
     */
    public function matches(string $value): bool
    {
        $values = $this->values()->pluck('value')->toArray();

        if ($this->operator === 'in') {
            return in_array($value, $values);
        }

        return ! in_array($value, $values); // not_in
    }
}
