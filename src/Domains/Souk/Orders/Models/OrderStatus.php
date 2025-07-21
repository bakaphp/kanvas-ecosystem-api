<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Models;

use Baka\Casts\Json;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Souk\Models\BaseModel;

/**
 * Class Order
 *
 * @property int $id
 * @property int $apps_id
 * @property int $order_types_id
 * @property string $uuid
 * @property string|null $slug
 * @property string|null $name
 * @property string|null $description
 * @property string|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class OrderStatus extends BaseModel
{
    protected $guarded = [];

    protected $casts = [
        'metadata' => Json::class,
        'is_default' => 'boolean',
        'is_final' => 'boolean',
    ];

    public function orderType(): BelongsTo
    {
        return $this->belongsTo(OrderTypes::class, 'order_types_id', 'id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(OrderStatusTransitions::class, 'from_status_id', 'id');
    }

    public function toTransitions(): HasMany
    {
        return $this->hasMany(OrderStatusTransitions::class, 'to_status_id', 'id');
    }

    public function fromTransitions(): HasMany
    {
        return $this->hasMany(OrderStatusTransitions::class, 'from_status_id', 'id');
    }

    public function isInitialState(): bool
    {
        return $this->is_default;
    }

    public function isDefaultState(): bool
    {
        return $this->is_default;
    }

    public function isFinalState(): bool
    {
        return $this->is_final;
    }
}
