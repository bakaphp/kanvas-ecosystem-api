<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Queries\Orders;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Souk\Orders\Models\Order;
use Spatie\Activitylog\Models\Activity;

class OrderActivityLogQuery
{
    public function getBuilder(Order $root, array $args): Builder
    {
        return Activity::query()
            ->where('subject_type', Order::class)
            ->where('subject_id', $root->getKey());
    }
}
