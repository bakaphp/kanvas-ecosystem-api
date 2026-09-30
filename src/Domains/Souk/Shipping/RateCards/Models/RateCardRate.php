<?php

declare(strict_types=1);

namespace Kanvas\Souk\Shipping\RateCards\Models;

use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Souk\Models\BaseModel;
use Override;

class RateCardRate extends BaseModel
{
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;

    protected $table = 'shipping_rate_card_rates';

    protected array $nervousSystemEventTypes = [];

    protected $fillable = [
        'rate_card_id',
        'zone',
        'max_grams',
        'amount',
        'transit_min_days',
        'transit_max_days',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'max_grams' => 'integer',
            'amount' => 'decimal:2',
            'transit_min_days' => 'integer',
            'transit_max_days' => 'integer',
        ];
    }

    public function rateCard(): BelongsTo
    {
        return $this->belongsTo(RateCard::class, 'rate_card_id');
    }
}
