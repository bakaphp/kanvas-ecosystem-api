<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Repositories;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Souk\Orders\Models\Order;

/**
 * Roadside-assistance orders by mechanic. The mechanic link lives inside the order's metadata JSON
 * (written by PrepareRoadsideAssistanceCaseAction), under either `assistance_case` or the legacy
 * `data.assistance_case` prefix — every filter here has to check both.
 */
class MechanicOrdersRepository
{
    public static function query(
        AppInterface $app,
        ?int $mechanicId = null,
        ?string $mechanicFilter = null,
        ?int $providerCompanyId = null
    ): Builder {
        $query = Order::fromApp($app)
            ->notDeleted()
            ->whereHas('orderType', fn ($q) => $q->where('name', OrderTypeEnum::ROADSIDE_ASSISTANCE->value));

        if ($providerCompanyId !== null) {
            $query->where(fn ($q) => self::whereMetadataInt($q, 'mechanic.company_id', $providerCompanyId));
        }

        if ($mechanicId === null) {
            return $query;
        }

        return $mechanicFilter === 'NOTIFIED'
            ? $query->where(fn ($q) => self::whereNotifiedMechanic($q, $mechanicId))
            : $query->where(fn ($q) => self::whereMetadataInt($q, 'mechanic.user_id', $mechanicId));
    }

    private static function whereMetadataInt(mixed $query, string $path, int $value): void
    {
        $query->whereRaw(
            "CAST(JSON_EXTRACT(metadata, '$.assistance_case." . $path . "') AS UNSIGNED) = ?",
            [$value]
        )->orWhereRaw(
            "CAST(JSON_EXTRACT(metadata, '$.data.assistance_case." . $path . "') AS UNSIGNED) = ?",
            [$value]
        );
    }

    /**
     * Notified mechanics are a JSON array that has been written both as ints and as strings over
     * time, so the id has to be matched with JSON_CONTAINS and JSON_SEARCH.
     */
    private static function whereNotifiedMechanic(mixed $query, int $mechanicId): void
    {
        $query->whereRaw(
            "JSON_CONTAINS(metadata, CAST(? AS JSON), '$.assistance_case.notified_mechanic_ids')",
            [$mechanicId]
        )->orWhereRaw(
            "JSON_CONTAINS(metadata, CAST(? AS JSON), '$.data.assistance_case.notified_mechanic_ids')",
            [$mechanicId]
        )->orWhereRaw(
            "JSON_SEARCH(metadata, 'one', ?, NULL, '$.assistance_case.notified_mechanic_ids') IS NOT NULL",
            [(string) $mechanicId]
        )->orWhereRaw(
            "JSON_SEARCH(metadata, 'one', ?, NULL, '$.data.assistance_case.notified_mechanic_ids') IS NOT NULL",
            [(string) $mechanicId]
        );
    }
}
