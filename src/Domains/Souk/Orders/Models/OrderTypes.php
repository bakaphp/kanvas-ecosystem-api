<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Models;

use Baka\Casts\Json;
use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\SlugTrait;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
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
    use DynamicSearchableTrait {
        search as public traitSearch;
    }
    use SlugTrait;

    protected $table = 'order_types';
    protected $guarded = [];

    protected $casts = [
        'total_statuses' => 'integer',
        'config' => Json::class,
    ];

    public function isExpirable(): bool
    {
        return (bool) ($this->config['expirable'] ?? false);
    }

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
            'objectID' => (string) $this->id,
            'id' => (string) $this->id,
            'name' => $this->name,
            'apps_id' => $this->apps_id,
            'companies_id' => $this->companies_id,
        ];
    }

    public function typesenseCollectionSchema(): array
    {
        return [
            'name' => $this->searchableAs(),
            'fields' => [
                [
                    'name' => 'objectID',
                    'type' => 'string',
                ],
                [
                    'name' => 'id',
                    'type' => 'string',
                ],
                [
                    'name' => 'name',
                    'type' => 'string',
                ],
                [
                    'name' => 'apps_id',
                    'type' => 'int64',
                ],
                [
                    'name' => 'companies_id',
                    'type' => 'int64',
                    'facet' => true,
                ],
            ],
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }

    public static function search($query = '', $callback = null)
    {
        $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
        $user = auth()->user();

        if ($user instanceof UserInterface && app()->bound(CompaniesBranches::class)) {
            $query->where('companies_id', app(CompaniesBranches::class)->company->getId());
        } elseif ($user instanceof UserInterface && ! $user->isAppOwner()) {
            $query->where('companies_id', $user->getCurrentCompany()->getId());
        }

        if ($query->model->isTypesense()) {
            $query->options(['query_by' => 'name']);
        }

        return $query;
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
