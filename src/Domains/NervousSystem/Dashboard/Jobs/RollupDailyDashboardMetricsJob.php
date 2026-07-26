<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Dashboard\Jobs;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Dashboard\Actions\RollupDashboardMetricsAction;
use Kanvas\NervousSystem\Metrics\Jobs\AbstractRollupDailyMetricsJob;
use Override;

/**
 * Cron entry point — runs nightly to roll up yesterday for every tenant
 * tuple that had any plan activity in the day. The fan-out + per-tuple
 * try/catch + logging shape lives in AbstractRollupDailyMetricsJob. The
 * reader's snapshot-missing fallback means a failed rollup is not
 * user-facing — it just degrades to live aggregation for that day until
 * the next run succeeds.
 */
class RollupDailyDashboardMetricsJob extends AbstractRollupDailyMetricsJob
{
    #[Override]
    protected function tenantTuplesForDay(Carbon $start, Carbon $end): Collection
    {
        return DB::connection('intelligence')
            ->table('nervous_system_plans')
            ->where('is_deleted', 0)
            ->whereBetween('completed_at', [$start, $end])
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
    protected function logPrefix(): string
    {
        return '[NS:Dashboard]';
    }
}
