<?php

declare(strict_types=1);

namespace Kanvas\Souk\Loyalty\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Souk\Models\BaseModel;
use Override;

/**
 * @property int $apps_id
 * @property int $loyalty_programs_id
 * @property string $name
 * @property string|null $description
 * @property bool $requires_existing_membership
 * @property int|null $min_purchase_count
 * @property int|null $max_purchase_count
 * @property float|null $min_spending_amount
 * @property float|null $max_spending_amount
 * @property array|null $required_tier_ids
 * @property array|null $allowed_user_segments
 * @property array|null $excluded_user_ids
 * @property bool $auto_enroll
 * @property int $priority
 * @property bool $is_active
 */
class LoyaltyProgramEligibility extends BaseModel
{
    use UuidTrait;

    protected $table = 'loyalty_program_eligibility';

    protected $fillable = [
        'apps_id',
        'loyalty_programs_id',
        'name',
        'description',
        'requires_existing_membership',
        'min_purchase_count',
        'max_purchase_count',
        'min_spending_amount',
        'max_spending_amount',
        'required_tier_ids',
        'allowed_user_segments',
        'excluded_user_ids',
        'auto_enroll',
        'priority',
        'is_active',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'requires_existing_membership' => 'boolean',
            'min_purchase_count' => 'integer',
            'max_purchase_count' => 'integer',
            'min_spending_amount' => 'decimal:2',
            'max_spending_amount' => 'decimal:2',
            'required_tier_ids' => Json::class,
            'allowed_user_segments' => Json::class,
            'excluded_user_ids' => Json::class,
            'auto_enroll' => 'boolean',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function loyaltyProgram(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'loyalty_programs_id');
    }
}
