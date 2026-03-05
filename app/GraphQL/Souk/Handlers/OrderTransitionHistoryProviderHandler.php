<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Handlers;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderProvider;
use Nuwave\Lighthouse\WhereConditions\WhereConditionsHandler;

class OrderTransitionHistoryProviderHandler extends WhereConditionsHandler
{
    public function __invoke(
        object $builder,
        array $whereConditions,
        ?Model $model = null,
        string $boolean = 'and',
    ): void {
        $companyId = (int) ($whereConditions['value'] ?? 0);

        $orderSubquery = Order::query()
            ->join(
                OrderProvider::getQualifiedTableName() . ' as op',
                'op.order_id',
                '=',
                'orders.id'
            )
            ->where('op.company_id', $companyId)
            ->where('orders.apps_id', app(Apps::class)->getId())
            ->select('orders.id');

        $builder->whereIn('order_id', $orderSubquery);
    }
}
