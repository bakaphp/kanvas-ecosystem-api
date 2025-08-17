<?php

declare(strict_types=1);

namespace Kanvas\Souk\Discounts\Models;

use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Souk\Models\BaseModel;

/**
 * Class DiscountConditionValue
 *
 * @property int $id
 * @property int $apps_id
 * @property int $condition_id
 * @property string $value
 * @property bool $is_deleted
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class DiscountConditionValue extends BaseModel
{
    use NoCompanyRelationshipTrait;

    protected $table = 'discount_condition_values';
    protected $guarded = [];

    protected $casts = [
        'is_deleted' => 'boolean',
    ];

    public function condition(): BelongsTo
    {
        return $this->belongsTo(DiscountCondition::class, 'condition_id');
    }
}
