<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Queries\Orders;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Activities\Models\Activity;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;

class OrderActivityLogQuery
{
    public function getBuilder(Order $root, array $args): Builder
    {
        return Activity::query()
            ->where('subject_type', Order::class)
            ->where('subject_id', $root->getKey());
    }

    public function getCompanyBuilder(mixed $root, array $args): Builder
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $query = Activity::query()
            ->where('subject_type', Order::class)
            ->forAppAndCompany($app, $company);

        if (! empty($args['order_number'])) {
            // activity_log and orders sit on different connections, so the ids are resolved
            // in PHP rather than as a subquery
            $orderIds = Order::query()
                ->fromApp($app)
                ->fromCompany($company)
                ->where('order_number', $args['order_number'])
                ->pluck('id');

            $query->whereIn('subject_id', $orderIds);
        }

        return $query;
    }
}
