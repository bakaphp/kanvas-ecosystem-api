<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Movipass\Builders;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;

class MechanicOrdersBuilder
{
    public function build(mixed $root, array $args): Builder
    {
        $userId = auth()->user()->getId();

        return Order::fromApp(app(Apps::class))
            ->notDeleted()
            ->where(function ($q) use ($userId) {
                $q->whereJsonContains('metadata->assistance_case->notified_mechanic_ids', $userId)
                    ->orWhere('metadata->assistance_case->mechanic->user_id', $userId);
            });
    }
}
