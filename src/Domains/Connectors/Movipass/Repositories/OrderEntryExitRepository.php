<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Repositories;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Souk\Orders\Models\OrderProvider;
use Kanvas\Souk\Orders\Models\OrderTransitionHistory;

/**
 * The status-change trail behind the entry/exit reports. Every dwell figure in the operation is a
 * pair of rows here — the transition into the opening status and the one into the closing status —
 * so this is what answers "when did that vehicle actually come in" as opposed to when the order
 * row was created.
 */
class OrderEntryExitRepository
{
    /**
     * @param array<int, int> $providerCompanyIds
     * @param array<int, string>|null $toStatusSlugs
     */
    public static function query(
        AppInterface $app,
        array $providerCompanyIds,
        ?string $orderTypeName = null,
        ?array $toStatusSlugs = null,
        ?string $since = null,
        ?string $until = null,
    ): Builder {
        return OrderTransitionHistory::query()
            ->where('apps_id', $app->getId())
            ->where('is_deleted', 0)
            ->when(
                $providerCompanyIds !== [],
                fn (Builder $q) => $q->whereIn('order_id', function ($sub) use ($providerCompanyIds) {
                    $sub->select('order_id')
                        ->from(OrderProvider::getQualifiedTableName())
                        ->whereIn('company_id', $providerCompanyIds);
                })
            )
            ->when(
                $orderTypeName !== null,
                fn (Builder $q) => $q->whereHas(
                    'order',
                    fn ($o) => $o->whereHas('orderType', fn ($t) => $t->where('name', $orderTypeName))
                )
            )
            ->when(
                $toStatusSlugs !== null && $toStatusSlugs !== [],
                fn (Builder $q) => $q->whereHas('toStatus', fn ($s) => $s->whereIn('slug', $toStatusSlugs))
            )
            ->when($since !== null && $since !== '', fn (Builder $q) => $q->where('changed_at', '>=', $since . ' 00:00:00'))
            ->when($until !== null && $until !== '', fn (Builder $q) => $q->where('changed_at', '<=', $until . ' 23:59:59'));
    }
}
