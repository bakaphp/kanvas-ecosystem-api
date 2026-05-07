<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Handlers;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Souk\Orders\Models\Order;
use Nuwave\Lighthouse\WhereConditions\WhereConditionsHandler;

class PaymentHasOrderHandler extends WhereConditionsHandler
{
    public function __invoke(
        object $builder,
        array $whereConditions,
        ?Model $model = null,
        string $boolean = 'and',
    ): void {
        $orderQuery = Order::query();

        if ($column = $whereConditions['column'] ?? null) {
            $this->assertValidColumnReference($column);
            $this->operator->applyConditions($orderQuery, $whereConditions, $boolean);
        }

        $builder->where('payable_type', Order::class)
            ->whereIn('payable_id', $orderQuery->select('id'));
    }
}
