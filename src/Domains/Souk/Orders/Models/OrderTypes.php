<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Models;

use Baka\Traits\DynamicSearchableTrait;
use Exception;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kanvas\Apps\Models\Apps;
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
    use DynamicSearchableTrait;

    protected $table = 'order_types';
    protected $guarded = [];

    protected $casts = [
        'total_statuses' => 'integer',
    ];

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

    public function searchableAs(): string
    {
        $app = $this->app ?? app(Apps::class);

        return config('scout.prefix') . ($app->get('app_custom_order_type_index') ?? 'order_type_index');
    }

    public function toSearchableArray(): array
    {
        return [
            'objectID' => $this->id,
            'id' => (string) $this->id,
            'name' => $this->name,
            'apps_id' => $this->apps_id,
            'companies_id' => $this->companies_id,
        ];
    }

    public function nextStatus(Order $order): OrderStatus
    {
        $currentStatus = $order->orderStatus;

        if (! $currentStatus) {
            throw new Exception('Order has no current status');
        }

        if ($currentStatus->isFinalState()) {
            throw new Exception("Order is already in final state: {$currentStatus->name}");
        }

        $validTargets = $currentStatus->fromTransitions()
            ->with('toStatus')
            ->get()
            ->pluck('toStatus')
            ->filter();

        $nextStatus = $validTargets
            ->where('sequence', '>', $currentStatus->sequence)
            ->sortBy('sequence')
            ->first();

        if (! $nextStatus) {
            throw new Exception("No valid next transition from status: {$currentStatus->name}");
        }

        return $nextStatus;
    }
}
