<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Dashboard\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Dashboard\Models\DashboardMetricsDaily;
use Kanvas\NervousSystem\Dashboard\Support\DashboardMetricsCache;

/**
 * Iterates a date range and a tenant scope, rolling up missing days.
 * Used by the kanvas:backfill-dashboard-metrics command (one-shot
 * historical fill on first deploy, or after a formula version bump).
 *
 * Without $force: skips dates where a snapshot already exists.
 * With $force: re-rolls everything (use after a formula change).
 */
class BackfillDashboardMetricsAction
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

        // Track every tenant tuple this backfill touched (rolled up OR
        // skipped) so we can flush their cache at the end. Skipped
        // tenants might still have stale cached responses from before
        // the backfill — clearing covers that case.
        // Key: "appId:companyId" → [appId, companyId].
        $tenantsTouched = [];

        $cursor = $this->from->copy()->startOfDay();
        $stop = $this->to->copy()->startOfDay();

        while ($cursor->lte($stop)) {
            $daysProcessed++;
            $start = $cursor->copy()->startOfDay();
            $end = $cursor->copy()->endOfDay();

            $tuples = DB::connection('intelligence')
                ->table('nervous_system_plans')
                ->where('is_deleted', 0)
                ->whereBetween('completed_at', [$start, $end])
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

                new RollupDashboardMetricsAction($app, $company, $cursor)->execute();
                $rolledUp++;
            }

            $cursor->addDay();
        }

        // Defensive cache flush — covers the case where every date for a
        // tenant was skipped (no rollup ran) but the operator still
        // expects the next dashboard read to be fresh.
        foreach ($tenantsTouched as [$appsId, $companiesId]) {
            DashboardMetricsCache::forget($appsId, $companiesId);
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
        return DashboardMetricsDaily::query()
            ->where('apps_id', $appsId)
            ->where('companies_id', $companiesId)
            ->whereDate('metric_date', $date->toDateString())
            ->exists();
    }
}
