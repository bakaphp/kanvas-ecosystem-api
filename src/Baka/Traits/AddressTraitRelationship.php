<?php

namespace Baka\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Locations\Models\Cities;
use Kanvas\Locations\Models\Countries;
use Kanvas\Locations\Models\States;

trait AddressTraitRelationship
{
    public function country(): BelongsTo
    {
        return $this->belongsTo(
            Countries::class,
            'countries_id',
            'id'
        );
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(
            States::class,
            'states_id',
            'id'
        );
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(
            Cities::class,
            'cities_id',
            'id'
        );
    }
}
