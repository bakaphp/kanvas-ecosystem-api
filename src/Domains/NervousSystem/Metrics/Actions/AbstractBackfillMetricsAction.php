<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Metrics\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Metrics\Support\AbstractMetricsCache;
use Kanvas\NervousSystem\Models\ImmutableBaseModel;

/**
 * Shared shape for the Pulse/Dashboard one-shot historical backfill
 * commands: walk date x tenant, upsert via the surface's rollup action,
 * skip dates with an existing snapshot unless $force is true.
 *
 * Also flushes the cache for every tenant touched (rolled-up OR
 * skipped) — a skipped tenant can still be holding a stale cached
 * response from before the backfill ran, and the operator running the
 * backfill expects the next read to be fresh either way.
 */
abstract class AbstractBackfillMetricsAction
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

            $tuples = $this->tenantTuplesForDay($start, $end);

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

                $this->rollup($app, $company, $cursor);
                $rolledUp++;
            }

            $cursor->addDay();
        }

        foreach ($tenantsTouched as [$appsId, $companiesId]) {
            $cacheClass = $this->cacheClass();
            $cacheClass::forget($appsId, $companiesId);
        }

        return [
            'rolled_up' => $rolledUp,
            'skipped' => $skipped,
            'days_processed' => $daysProcessed,
            'tenants_cache_cleared' => count($tenantsTouched),
        ];
    }

    /**
     * @return iterable<object{apps_id: int|string, companies_id: int|string}>
     */
    abstract protected function tenantTuplesForDay(Carbon $start, Carbon $end): iterable;

    abstract protected function rollup(Apps $app, Companies $company, Carbon $date): void;

    /** @return class-string<ImmutableBaseModel> */
    abstract protected function modelClass(): string;

    /** @return class-string<AbstractMetricsCache> */
    abstract protected function cacheClass(): string;

    private function snapshotExists(int $appsId, int $companiesId, Carbon $date): bool
    {
        $modelClass = $this->modelClass();

        return $modelClass::query()
            ->where('apps_id', $appsId)
            ->where('companies_id', $companiesId)
            ->whereDate('metric_date', $date->toDateString())
            ->exists();
    }
}
