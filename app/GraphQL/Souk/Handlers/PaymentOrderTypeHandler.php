<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Handlers;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Nuwave\Lighthouse\WhereConditions\WhereConditionsHandler;

class PaymentOrderTypeHandler extends WhereConditionsHandler
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

        $builder->where('payable_type', Order::class)
            ->whereIn(
                'payable_id',
                Order::query()
                ->whereIn('order_types_id', $orderTypeQuery->select('id'))
                ->select('id')
            );
    }
}
