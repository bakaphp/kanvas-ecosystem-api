<?php

declare(strict_types=1);

namespace Kanvas\Souk\Loyalty\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Souk\Loyalty\Factories\LoyaltyProgramFactory;
use Kanvas\Souk\Models\BaseModel;
use Kanvas\Souk\Referrals\Models\ReferralCode;
use Override;

/**
 * @property int $apps_id
 * @property int $companies_id
 * @property string $name
 * @property string|null $description
 * @property float $points_per_dollar
 * @property float $earn_multiplier
 * @property int|null $expiration_days
 * @property bool $is_active
 * @property bool $referral_enabled
 * @property string $referral_strategy
 * @property array|null $referral_config
 */
class LoyaltyProgram extends BaseModel
{
    use UuidTrait;

    protected $table = 'loyalty_programs';

    protected $fillable = [
        'apps_id',
        'companies_id',
        'name',
        'description',
        'points_per_dollar',
        'earn_multiplier',
        'expiration_days',
        'is_active',
        'referral_enabled',
        'referral_strategy',
        'referral_config',
        'meta',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'points_per_dollar' => 'decimal:2',
            'earn_multiplier' => 'decimal:2',
            'expiration_days' => 'integer',
            'is_active' => 'boolean',
            'referral_enabled' => 'boolean',
            'referral_config' => Json::class,
            'meta' => Json::class,
        ];
    }

    public function tiers(): HasMsany
    {
        return $this->hasMany(LoyaltyTier::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(LoyaltyOffer::class);
    }

    public function eligibilityRules(): HasMany
    {
        return $this->hasMany(LoyaltyProgramEligibility::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(LoyaltyTierMembership::class);
    }

    public function referralCodes(): HasMany
    {
        return $this->hasMany(ReferralCode::class);
    }

    public function assignmentAudits(): HasMany
    {
        return $this->hasMany(LoyaltyProgramAssignmentAudit::class);
    }

    public function orderLoyaltyPoints(): HasMany
    {
        return $this->hasMany(OrderLoyaltyPoints::class);
    }

    /**
     * Check if program is active.
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Check if referrals are enabled.
     */
    public function hasReferralsEnabled(): bool
    {
        return $this->referral_enabled;
    }

    /**
     * Get points earned per dollar spent.
     */
    public function getPointsPerDollar(): float
    {
        return (float) $this->points_per_dollar;
    }

    /**
     * Get earning multiplier.
     */
    public function getEarnMultiplier(): float
    {
        return (float) $this->earn_multiplier;
    }

    #[Override]
    protected static function newFactory()
    {
        return new LoyaltyProgramFactory();
    }
}
