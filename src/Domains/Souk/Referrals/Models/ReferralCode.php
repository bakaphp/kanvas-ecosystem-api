<?php

declare(strict_types=1);

namespace Kanvas\Souk\Referrals\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Loyalty\Models\LoyaltyProgram;
use Kanvas\Souk\Models\BaseModel;
use Kanvas\Souk\Referrals\Factories\ReferralCodeFactory;
use Override;

/**
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property string $code
 * @property int $loyalty_programs_id
 * @property int|null $discounts_id
 * @property int $referrer_reward
 * @property int $referee_reward
 * @property float $referee_discount
 * @property int|null $max_uses
 * @property int $current_uses
 * @property \DateTimeInterface|null $expires_at
 * @property bool $is_active
 * @property string $strategy
 * @property array|null $meta
 */
class ReferralCode extends BaseModel
{
    use UuidTrait;

    protected $table = 'referral_codes';

    protected $attributes = [
        'companies_id' => 0,
    ];

    protected $fillable = [
        'apps_id',
        'companies_id',
        'users_id',
        'code',
        'loyalty_programs_id',
        'discounts_id',
        'referrer_reward',
        'referee_reward',
        'referee_discount',
        'max_uses',
        'current_uses',
        'expires_at',
        'is_active',
        'strategy',
        'meta',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'referrer_reward' => 'integer',
            'referee_reward' => 'integer',
            'referee_discount' => 'decimal:2',
            'max_uses' => 'integer',
            'current_uses' => 'integer',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
            'meta' => Json::class,
        ];
    }

    public function loyaltyProgram(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'loyalty_programs_id');
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class, 'discounts_id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(ReferralRedemption::class, 'referral_codes_id');
    }

    public function orderReferralCodes(): HasMany
    {
        return $this->hasMany(OrderReferralCode::class, 'referral_codes_id');
    }

    /**
     * Check if referral code is still valid.
     */
    public function isValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->expires_at && now()->isAfter($this->expires_at)) {
            return false;
        }

        if ($this->max_uses && $this->current_uses >= $this->max_uses) {
            return false;
        }

        return true;
    }

    /**
     * Increment usage count.
     */
    public function incrementUsage(): self
    {
        $this->increment('current_uses');

        return $this;
    }

    #[Override]
    protected static function newFactory()
    {
        return new ReferralCodeFactory();
    }
}
