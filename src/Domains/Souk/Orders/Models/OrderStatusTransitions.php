<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Souk\Models\BaseModel;

/**
 * Class Order
 *
 * @property int $id
 * @property int $order_types_id
 * @property int $from_status_id
 * @property int $to_status_id
 * @property string $name
 * @property string|null $description
 * @property string|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class OrderStatusTransitions extends BaseModel
{
    protected $table = 'order_status_transitions';
    protected $guarded = [];

    protected $casts = [
        'metadata' => Json::class
    ];

    public function orderType(): BelongsTo
    {
        return $this->belongsTo(OrderTypes::class, 'order_types_id', 'id');
    }

    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(OrderStatus::class, 'from_status_id', 'id');
    }

    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(OrderStatus::class, 'to_status_id', 'id');
    }
}
