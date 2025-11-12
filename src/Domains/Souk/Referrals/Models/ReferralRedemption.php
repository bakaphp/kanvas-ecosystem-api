<?php

declare(strict_types=1);

namespace Kanvas\Souk\Referrals\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Models\BaseModel;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Referrals\Factories\ReferralRedemptionFactory;
use Kanvas\Users\Models\Users;
use Override;

/**
 * @property int $referral_codes_id
 * @property int $referrer_user_id
 * @property int $referee_user_id
 * @property int $orders_id
 * @property int $discounts_id
 * @property int $referrer_points_awarded
 * @property float $referee_discount_amount
 * @property string $status
 * @property \DateTimeInterface|null $redeemed_at
 */
class ReferralRedemption extends BaseModel
{
    use UuidTrait;

    protected $table = 'referral_redemptions';

    protected $attributes = [
        'companies_id' => 0,
    ];

    protected $fillable = [
        'apps_id',
        'companies_id',
        'referral_codes_id',
        'referrer_user_id',
        'referee_user_id',
        'orders_id',
        'discounts_id',
        'referrer_points_awarded',
        'referee_discount_amount',
        'status',
        'redeemed_at',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'referrer_points_awarded' => 'integer',
            'referee_discount_amount' => 'decimal:2',
            'redeemed_at' => 'datetime',
        ];
    }

    public function referralCode(): BelongsTo
    {
        return $this->belongsTo(ReferralCode::class, 'referral_codes_id');
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'referrer_user_id');
    }

    public function referee(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'referee_user_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'orders_id');
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class, 'discounts_id');
    }

    /**
     * Mark redemption as completed.
     */
    public function markAsCompleted(): self
    {
        $this->update([
            'status' => 'completed',
            'redeemed_at' => now(),
        ]);

        return $this;
    }

    #[Override]
    protected static function newFactory()
    {
        return new ReferralRedemptionFactory();
    }
}
