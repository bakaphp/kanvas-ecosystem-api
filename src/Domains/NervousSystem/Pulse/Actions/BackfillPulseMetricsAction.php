<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Pulse\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Pulse\Models\PulseMetricsDaily;
use Kanvas\NervousSystem\Pulse\Support\PulseMetricsCache;

/**
 * One-shot historical backfill of pulse metrics. Iterates date × tenant
 * (only tenants with ledger activity per day), upserts via
 * RollupPulseMetricsAction. Skips dates with an existing snapshot
 * unless $force is true.
 *
 * Also flushes the cache for every tenant touched (rolled-up OR
 * skipped) — see the analogous behavior in BackfillDashboardMetrics.
 */
class BackfillPulseMetricsAction
{
    public function __construct(
        protected readonly Carbon $from,
        protected readonly Carbon $to,
        protected readonly ?Apps $app = null,
        protected readonly bool $force = false,
    ) {
    }

    /**
     * @return array{rolled_up: int, skipped: int, days_processed: int, tenants_cache_cleared: int}
     */
    public function execute(): array
    {
        $rolledUp = 0;
        $skipped = 0;
        $daysProcessed = 0;
        $tenantsTouched = [];

        $cursor = $this->from->copy()->startOfDay();
        $stop = $this->to->copy()->startOfDay();

        while ($cursor->lte($stop)) {
            $daysProcessed++;
            $start = $cursor->copy()->startOfDay();
            $end = $cursor->copy()->endOfDay();

            $tuples = DB::connection('intelligence')
                ->table('nervous_system_events')
                ->whereBetween('occurred_at', [$start, $end])
                ->when($this->app !== null, fn ($q) => $q->where('apps_id', $this->app->getId()))
                ->select('apps_id', 'companies_id')
                ->distinct()
                ->get();

            foreach ($tuples as $tuple) {
                $tenantsTouched[$tuple->apps_id . ':' . $tuple->companies_id] = [
                    (int) $tuple->apps_id,
                    (int) $tuple->companies_id,
                ];

                if (! $this->force && $this->snapshotExists($tuple->apps_id, $tuple->companies_id, $cursor)) {
                    $skipped++;

                    continue;
                }

                $app = Apps::find($tuple->apps_id);
                $company = Companies::find($tuple->companies_id);

                if ($app === null || $company === null) {
                    continue;
                }

                new RollupPulseMetricsAction($app, $company, $cursor)->execute();
                $rolledUp++;
            }

            $cursor->addDay();
        }

        foreach ($tenantsTouched as [$appsId, $companiesId]) {
            PulseMetricsCache::forget($appsId, $companiesId);
        }

        return [
            'rolled_up' => $rolledUp,
            'skipped' => $skipped,
            'days_processed' => $daysProcessed,
            'tenants_cache_cleared' => count($tenantsTouched),
        ];
    }

    private function snapshotExists(int $appsId, int $companiesId, Carbon $date): bool
    {
        return PulseMetricsDaily::query()
            ->where('apps_id', $appsId)
            ->where('companies_id', $companiesId)
            ->whereDate('metric_date', $date->toDateString())
            ->exists();
    }
}
