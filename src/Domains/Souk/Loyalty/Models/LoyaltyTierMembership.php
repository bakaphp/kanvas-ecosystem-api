<?php

declare(strict_types=1);

namespace Kanvas\Souk\Loyalty\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Souk\Loyalty\Factories\LoyaltyTierMembershipFactory;
use Kanvas\Souk\Models\BaseModel;
use Override;

/**
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property int $loyalty_tiers_id
 * @property int $loyalty_programs_id
 * @property int $lifetime_points
 * @property int $current_points
 * @property \DateTime|null $tier_promoted_at
 */
class LoyaltyTierMembership extends BaseModel
{
    use UuidTrait;

    protected $table = 'loyalty_tier_memberships';

    protected $attributes = [
        'companies_id' => 0,
    ];

    protected $fillable = [
        'apps_id',
        'companies_id',
        'users_id',
        'loyalty_tiers_id',
        'loyalty_programs_id',
        'lifetime_points',
        'current_points',
        'tier_promoted_at',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'lifetime_points' => 'integer',
            'current_points' => 'integer',
            'tier_promoted_at' => 'datetime',
        ];
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(LoyaltyTier::class, 'loyalty_tiers_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'loyalty_programs_id');
    }

    public function orderLoyaltyPoints(): HasMany
    {
        return $this->hasMany(OrderLoyaltyPoints::class);
    }

    /**
     * Add points to this membership.
     */
    public function addPoints(int $points): self
    {
        $this->increment('current_points', $points);
        $this->increment('lifetime_points', $points);

        return $this;
    }

    /**
     * Subtract points from this membership.
     */
    public function subtractPoints(int $points): self
    {
        $this->decrement('current_points', $points);

        return $this;
    }

    /**
     * Check if user can afford to spend these points.
     */
    public function canAfford(int $points): bool
    {
        return $this->current_points >= $points;
    }

    /**
     * Get current earning multiplier based on tier.
     */
    public function getEarningMultiplier(): float
    {
        return $this->tier->getEarningMultiplier();
    }

    #[Override]
    protected static function newFactory()
    {
        return new LoyaltyTierMembershipFactory();
    }
}
