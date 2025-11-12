<?php

declare(strict_types=1);

namespace Kanvas\Souk\Loyalty\Models;

use Baka\Casts\Json;
use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Souk\Loyalty\Factories\LoyaltyTierFactory;
use Kanvas\Souk\Models\BaseModel;
use Override;

/**
 * @property int $loyalty_programs_id
 * @property int $companies_id
 * @property string $name
 * @property int $level
 * @property int $min_points
 * @property float $earning_multiplier
 * @property array|null $benefits
 */
class LoyaltyTier extends BaseModel
{
    use UuidTrait;
    use NoAppRelationshipTrait;

    protected $table = 'loyalty_tiers';

    protected $attributes = [
        'companies_id' => 0,
    ];

    protected $fillable = [
        'loyalty_programs_id',
        'companies_id',
        'name',
        'level',
        'min_points',
        'earning_multiplier',
        'benefits',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'min_points' => 'integer',
            'earning_multiplier' => 'decimal:2',
            'benefits' => Json::class,
        ];
    }

    public function loyaltyProgram(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(LoyaltyTierMembership::class);
    }

    /**
     * Get the earning multiplier for this tier.
     */
    public function getEarningMultiplier(): float
    {
        return (float) $this->earning_multiplier;
    }

    /**
     * Check if a user qualifies for this tier based on points.
     */
    public function qualifiesForTier(int $points): bool
    {
        return $points >= $this->min_points;
    }

    /**
     * Override bootAppsIdTrait to prevent setting apps_id since this model doesn't have that column.
     */
    #[Override]
    public static function bootAppsIdTrait(): void
    {
        // Do nothing - LoyaltyTier doesn't have apps_id column
    }

    #[Override]
    protected static function newFactory()
    {
        return new LoyaltyTierFactory();
    }
}
