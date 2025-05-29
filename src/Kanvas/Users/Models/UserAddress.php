<?php

declare(strict_types=1);

namespace Kanvas\Users\Models;

use Kanvas\Locations\Models\Cities;
use Kanvas\Locations\Models\Countries;
use Kanvas\Locations\Models\States;
use Kanvas\Models\BaseModel;

class UserAddress extends BaseModel
{
    protected $table = 'users_address';

    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function state()
    {
        return $this->belongsTo(States::class, 'state_id');
    }

    public function city()
    {
        return $this->belongsTo(Cities::class, 'city_id');
    }

    public function country()
    {
        return $this->belongsTo(Countries::class, 'countries_id');
    }
}
