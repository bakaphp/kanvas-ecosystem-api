<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Movipass\Builders;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Souk\Orders\Models\Order;

class MechanicOrdersBuilder
{
    public function build(mixed $root, array $args): Builder
    {
        $query = Order::fromApp(app(Apps::class))->notDeleted();

        if ($args['all'] ?? false) {
            return $query->whereHas('orderType', fn ($q) => $q->where('name', OrderTypeEnum::ROADSIDE_ASSISTANCE->value));
        }

        $userId = (int) auth()->user()->getId();

        return $query->where(function ($q) use ($userId) {
            $q->whereRaw(
                "JSON_CONTAINS(metadata, CAST(? AS JSON), '$.assistance_case.notified_mechanic_ids')",
                [$userId]
            )
            ->orWhereRaw(
                "CAST(JSON_EXTRACT(metadata, '$.assistance_case.mechanic.user_id') AS UNSIGNED) = ?",
                [$userId]
            );
        });
    }
}
