<?php

declare(strict_types=1);

namespace Kanvas\Souk\Loyalty\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Souk\Models\BaseModel;
use Kanvas\Souk\Orders\Models\Order;
use Override;

/**
 * @property int $apps_id
 * @property int $companies_id
 * @property int $orders_id
 * @property int $loyalty_programs_id
 * @property int $loyalty_tier_memberships_id
 * @property int $points_earned
 * @property int $points_redeemed
 * @property string $status
 * @property \DateTime|null $credited_at
 */
class OrderLoyaltyPoints extends BaseModel
{
    use UuidTrait;

    protected $table = 'order_loyalty_points';

    protected $attributes = [
        'companies_id' => 0,
    ];

    protected $fillable = [
        'apps_id',
        'companies_id',
        'orders_id',
        'loyalty_programs_id',
        'loyalty_tier_memberships_id',
        'points_earned',
        'points_redeemed',
        'status',
        'credited_at',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'points_earned' => 'integer',
            'points_redeemed' => 'integer',
            'points_net' => 'integer',
            'credited_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'orders_id');
    }

    public function loyaltyProgram(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'loyalty_programs_id');
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(LoyaltyTierMembership::class, 'loyalty_tier_memberships_id');
    }

    /**
     * Calculate net points (earned - redeemed).
     */
    public function getNetPoints(): int
    {
        return $this->points_earned - $this->points_redeemed;
    }

    /**
     * Mark points as credited.
     */
    public function markAsCredited(): self
    {
        $this->update([
            'status' => 'credited',
            'credited_at' => now(),
        ]);

        return $this;
    }
}
