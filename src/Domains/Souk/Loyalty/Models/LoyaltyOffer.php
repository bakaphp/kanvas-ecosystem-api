<?php

declare(strict_types=1);

namespace Kanvas\Souk\Loyalty\Models;

use Baka\Casts\Json;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Souk\Loyalty\Factories\LoyaltyOfferFactory;
use Kanvas\Souk\Models\BaseModel;
use Override;

/**
 * @property int $loyalty_programs_id
 * @property int $apps_id
 * @property int $companies_id
 * @property string $name
 * @property string $headline
 * @property string $description
 * @property string $offer_type
 * @property string $trigger_type
 * @property array $trigger_value
 * @property int $reward_value
 * @property int $expiration_hours
 * @property string $status
 */
class LoyaltyOffer extends BaseModel
{
    use UuidTrait;

    protected $table = 'loyalty_offers';

    protected $attributes = [
        'companies_id' => 0,
    ];

    protected $fillable = [
        'loyalty_programs_id',
        'apps_id',
        'companies_id',
        'name',
        'headline',
        'description',
        'offer_type',
        'trigger_type',
        'trigger_value',
        'reward_value',
        'expiration_hours',
        'status',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'trigger_value' => Json::class,
            'reward_value' => 'integer',
            'expiration_hours' => 'integer',
        ];
    }

    public function loyaltyProgram(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'loyalty_programs_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LoyaltyOfferAssignment::class, 'loyalty_offers_id');
    }

    /**
     * Check if offer is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if offer is expired.
     */
    public function isExpired(): bool
    {
        return $this->status === 'archived';
    }

    /**
     * Get the expiration datetime for a new assignment.
     */
    public function getExpirationDateTime(): \DateTime
    {
        return (new \DateTime())->add(new \DateInterval('PT' . $this->expiration_hours . 'H'));
    }

    #[Override]
    protected static function newFactory()
    {
        return new LoyaltyOfferFactory();
    }
}
