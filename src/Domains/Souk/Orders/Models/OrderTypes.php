<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Souk\Models\BaseModel;

/**
 * Class Order
 *
 * @property int $id
 * @property int $apps_id
 * @property int companies_id
 * @property string $name
 * */
class OrderTypes extends BaseModel
{
    protected $table = 'order_types';
    protected $guarded = [];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'order_types_id', 'id');
    }
}
