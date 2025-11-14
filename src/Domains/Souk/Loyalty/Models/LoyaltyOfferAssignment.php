<?php

declare(strict_types=1);

namespace Kanvas\Souk\Loyalty\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Souk\Models\BaseModel;
use Override;

/**
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property int $loyalty_offers_id
 * @property string $status
 * @property \DateTime|null $viewed_at
 * @property \DateTime|null $accepted_at
 * @property \DateTime|null $expires_at
 */
class LoyaltyOfferAssignment extends BaseModel
{
    use UuidTrait;

    protected $table = 'loyalty_offer_assignments';

    protected $attributes = [
        'companies_id' => 0,
    ];

    protected $fillable = [
        'apps_id',
        'companies_id',
        'users_id',
        'loyalty_offers_id',
        'status',
        'viewed_at',
        'accepted_at',
        'expires_at',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(LoyaltyOffer::class, 'loyalty_offers_id');
    }

    /**
     * Mark offer as viewed.
     */
    public function markAsViewed(): self
    {
        $this->update([
            'status' => 'viewed',
            'viewed_at' => now(),
        ]);

        return $this;
    }

    /**
     * Mark offer as accepted.
     */
    public function markAsAccepted(): self
    {
        $this->update([
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        return $this;
    }

    /**
     * Check if offer has expired.
     */
    public function hasExpired(): bool
    {
        return now()->isAfter($this->expires_at);
    }

    /**
     * Check if offer is still available.
     */
    public function isAvailable(): bool
    {
        return $this->status === 'assigned' && ! $this->hasExpired();
    }
}
