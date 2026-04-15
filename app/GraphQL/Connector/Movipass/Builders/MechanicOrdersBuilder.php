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
        $userId = (int) auth()->user()->getId();

        return Order::fromApp(app(Apps::class))
            ->notDeleted()
            ->where(function ($q) use ($userId) {
                $q->whereRaw(
                    "JSON_CONTAINS(metadata, CAST(? AS JSON), '$.assistance_case.notified_mechanic_ids')",
                    [$userId]
                )
                ->orWhereRaw(
                    "JSON_EXTRACT(metadata, '$.assistance_case.mechanic.user_id') = ?",
                    [$userId]
                );
            });
    }
}
