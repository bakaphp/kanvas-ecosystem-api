<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Pulse\Jobs;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Metrics\Jobs\AbstractRollupDailyMetricsJob;
use Kanvas\NervousSystem\Pulse\Actions\RollupPulseMetricsAction;
use Override;

/**
 * Cron entry — rolls up yesterday's Pulse metrics for every tenant tuple
 * that had ledger activity in the day. The fan-out + per-tuple
 * try/catch + logging shape lives in AbstractRollupDailyMetricsJob.
 */
class RollupDailyPulseMetricsJob extends AbstractRollupDailyMetricsJob
{
    #[Override]
    protected function tenantTuplesForDay(Carbon $start, Carbon $end): Collection
    {
        return DB::connection('intelligence')
            ->table('nervous_system_events')
            ->whereBetween('occurred_at', [$start, $end])
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
    protected function logPrefix(): string
    {
        return '[NS:Pulse]';
    }
}
