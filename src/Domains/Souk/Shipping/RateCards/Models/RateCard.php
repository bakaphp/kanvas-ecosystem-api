<?php

declare(strict_types=1);

namespace Kanvas\Souk\Shipping\RateCards\Models;

use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Souk\Models\BaseModel;
use Kanvas\Souk\Shipping\RateCards\Concerns\ScopesToCompanyApp;
use Override;

class RateCard extends BaseModel
{
    use CascadeSoftDeletes;
    use ScopesToCompanyApp;

    protected $table = 'shipping_rate_cards';

    protected $cascadeDeletes = ['rates'];

    protected $fillable = [
        'companies_id',
        'apps_id',
        'provider',
        'service_code',
        'name',
        'currency',
        'weight_unit',
        'fixed_charge',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'fixed_charge' => 'decimal:2',
        ];
    }

    public function rates(): HasMany
    {
        return $this->hasMany(RateCardRate::class, 'rate_card_id');
    }
}
