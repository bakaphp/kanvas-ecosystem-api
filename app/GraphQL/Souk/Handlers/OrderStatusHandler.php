<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Handlers;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Nuwave\Lighthouse\WhereConditions\WhereConditionsHandler;

class OrderStatusHandler extends WhereConditionsHandler
{
    public function __invoke(
        object $builder,
        array $whereConditions,
        ?Model $model = null,
        string $boolean = 'and',
    ): void {
        $orderStatusQuery = OrderStatus::query();
        if ($column = $whereConditions['column'] ?? null) {
            $this->assertValidColumnReference($column);
            $this->operator->applyConditions($orderStatusQuery, $whereConditions, $boolean);
        }

        $builder->whereHas('orderType', function ($query) use ($orderStatusQuery) {
            $query->mergeConstraintsFrom($orderStatusQuery);
        });
    }
}
