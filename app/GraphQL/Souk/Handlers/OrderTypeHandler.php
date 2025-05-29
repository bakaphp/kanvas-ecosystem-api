<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Handlers;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Nuwave\Lighthouse\WhereConditions\WhereConditionsHandler;

class OrderTypeHandler extends WhereConditionsHandler
{
    public function __invoke(
        object $builder,
        array $whereConditions,
        ?Model $model = null,
        string $boolean = 'and',
    ): void {
        $orderTypeQuery = OrderTypes::query();
        if ($column = $whereConditions['column'] ?? null) {
            $this->assertValidColumnReference($column);
            $this->operator->applyConditions($orderTypeQuery, $whereConditions, $boolean);
        }

        $builder->whereHas('orderType', function ($query) use ($orderTypeQuery) {
            $query->mergeConstraintsFrom($orderTypeQuery);
        });
    }
}
