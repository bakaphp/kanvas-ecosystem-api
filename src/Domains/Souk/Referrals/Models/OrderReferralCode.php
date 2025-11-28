<?php

declare(strict_types=1);

namespace Kanvas\Souk\Referrals\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Souk\Models\BaseModel;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;
use Override;

/**
 * @property int $orders_id
 * @property int $referral_codes_id
 * @property int $referrer_user_id
 * @property int $referee_user_id
 * @property int $referrer_points_earned
 * @property float $referee_discount_applied
 * @property string $status
 * @property \DateTimeInterface|null $completed_at
 */
class OrderReferralCode extends BaseModel
{
    use UuidTrait;

    protected $table = 'order_referral_codes';

    protected $attributes = [
        'companies_id' => 0,
    ];

    protected $fillable = [
        'apps_id',
        'companies_id',
        'orders_id',
        'referral_codes_id',
        'referrer_user_id',
        'referee_user_id',
        'referrer_points_earned',
        'referee_discount_applied',
        'status',
        'completed_at',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'referrer_points_earned' => 'integer',
            'referee_discount_applied' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'orders_id');
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

    /**
     * Mark as completed.
     */
    public function markAsCompleted(): self
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        return $this;
    }
}
