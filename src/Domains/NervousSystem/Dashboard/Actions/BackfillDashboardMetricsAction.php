<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Dashboard\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Dashboard\Models\DashboardMetricsDaily;
use Kanvas\NervousSystem\Dashboard\Support\DashboardMetricsCache;
use Kanvas\NervousSystem\Metrics\Actions\AbstractBackfillMetricsAction;
use Override;

/**
 * Iterates a date range and tenant scope, rolling up missing days from
 * nervous_system_plans activity. Used by kanvas:backfill-dashboard-metrics
 * (one-shot historical fill on first deploy, or after a formula version
 * bump). The date x tenant walk + skip/force + cache-flush shape lives
 * in AbstractBackfillMetricsAction.
 */
class BackfillDashboardMetricsAction extends AbstractBackfillMetricsAction
{
    #[Override]
    protected function tenantTuplesForDay(Carbon $start, Carbon $end): iterable
    {
        return DB::connection('intelligence')
            ->table('nervous_system_plans')
            ->where('is_deleted', 0)
            ->whereBetween('completed_at', [$start, $end])
            ->when($this->app !== null, fn ($q) => $q->where('apps_id', $this->app->getId()))
            ->select('apps_id', 'companies_id')
            ->distinct()
            ->get();
    }

    #[Override]
    protected function rollup(Apps $app, Companies $company, Carbon $date): void
    {
        new RollupDashboardMetricsAction($app, $company, $date)->execute();
    }

    #[Override]
    protected function modelClass(): string
    {
        return DashboardMetricsDaily::class;
    }

    #[Override]
    protected function cacheClass(): string
    {
        return DashboardMetricsCache::class;
    }
}
