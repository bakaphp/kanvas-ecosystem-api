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
        $query = Order::fromApp(app(Apps::class))
            ->notDeleted()
            ->whereHas('orderType', fn ($q) => $q->where('name', OrderTypeEnum::ROADSIDE_ASSISTANCE->value));

        if (isset($args['provider_id'])) {
            $providerId = (int) $args['provider_id'];
            $query->where(function ($q) use ($providerId) {
                $q->whereRaw(
                    "CAST(JSON_EXTRACT(metadata, '$.assistance_case.mechanic.company_id') AS UNSIGNED) = ?",
                    [$providerId]
                )
                ->orWhereRaw(
                    "CAST(JSON_EXTRACT(metadata, '$.data.assistance_case.mechanic.company_id') AS UNSIGNED) = ?",
                    [$providerId]
                );
            });
        }

        if ($args['all'] ?? false) {
            return $query;
        }

        $mechanicId = isset($args['mechanic_id'])
            ? (int) $args['mechanic_id']
            : (int) auth()->user()->getId();

        return $query->where(function ($q) use ($mechanicId) {
            $q->whereRaw(
                "JSON_CONTAINS(metadata, CAST(? AS JSON), '$.assistance_case.notified_mechanic_ids')",
                [$mechanicId]
            )
            ->orWhereRaw(
                "JSON_CONTAINS(metadata, CAST(? AS JSON), '$.data.assistance_case.notified_mechanic_ids')",
                [$mechanicId]
            )
            ->orWhereRaw(
                "CAST(JSON_EXTRACT(metadata, '$.assistance_case.mechanic.user_id') AS UNSIGNED) = ?",
                [$mechanicId]
            )
            ->orWhereRaw(
                "CAST(JSON_EXTRACT(metadata, '$.data.assistance_case.mechanic.user_id') AS UNSIGNED) = ?",
                [$mechanicId]
            );
        });
    }
}
