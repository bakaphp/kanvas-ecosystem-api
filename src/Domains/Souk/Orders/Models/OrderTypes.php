<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Souk\Models\BaseModel;

class OrderTypes extends BaseModel
{
    protected $table = 'order_types';
    protected $guarded = [];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'order_types_id', 'id');
    }
}
