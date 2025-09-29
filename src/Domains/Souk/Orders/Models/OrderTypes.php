<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kanvas\Souk\Models\BaseModel;
use Kanvas\Souk\Traits\DefaultTrait;

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
    use DefaultTrait;
    protected $table = 'order_types';
    protected $guarded = [];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'order_types_id', 'id');
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(OrderStatus::class, 'order_types_id', 'id');
    }

    public function defaultStatus(): HasOne
    {
        return $this->hasOne(OrderStatus::class, 'order_types_id', 'id')->where('is_default', true);
    }
}
