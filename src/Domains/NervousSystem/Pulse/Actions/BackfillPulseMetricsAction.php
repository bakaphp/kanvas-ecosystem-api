<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Pulse\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Metrics\Actions\AbstractBackfillMetricsAction;
use Kanvas\NervousSystem\Pulse\Models\PulseMetricsDaily;
use Kanvas\NervousSystem\Pulse\Support\PulseMetricsCache;
use Override;

/**
 * One-shot historical backfill of Pulse metrics, scoped to tenants with
 * ledger activity per day (nervous_system_events). The date x tenant
 * walk + skip/force + cache-flush shape lives in
 * AbstractBackfillMetricsAction.
 */
class BackfillPulseMetricsAction extends AbstractBackfillMetricsAction
{
    #[Override]
    protected function tenantTuplesForDay(Carbon $start, Carbon $end): iterable
    {
        return DB::connection('intelligence')
            ->table('nervous_system_events')
            ->whereBetween('occurred_at', [$start, $end])
            ->when($this->app !== null, fn ($q) => $q->where('apps_id', $this->app->getId()))
            ->select('apps_id', 'companies_id')
            ->distinct()
            ->get();
    }

    #[Override]
    protected function rollup(Apps $app, Companies $company, Carbon $date): void
    {
        new RollupPulseMetricsAction($app, $company, $date)->execute();
    }

    #[Override]
    protected function modelClass(): string
    {
        return PulseMetricsDaily::class;
    }

    #[Override]
    protected function cacheClass(): string
    {
        return PulseMetricsCache::class;
    }
}
