<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Dashboard\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\NervousSystem\Dashboard\DataTransferObject\DashboardAggregateRow;
use Kanvas\NervousSystem\Dashboard\Models\DashboardMetricsDaily;
use Kanvas\NervousSystem\Dashboard\Services\DashboardMetricsAggregatorService;
use Kanvas\NervousSystem\Dashboard\Support\DashboardMetricsCache;
use Kanvas\NervousSystem\Metrics\Actions\AbstractRollupMetricsAction;
use Override;

/**
 * Snapshot one (app, company, date) tuple. The idempotent-upsert +
 * ledger-emit + cache-bust shape lives in AbstractRollupMetricsAction;
 * this class supplies only what's Dashboard-specific — the aggregator,
 * snapshot columns, event type/payload, model, and cache.
 */
class RollupDashboardMetricsAction extends AbstractRollupMetricsAction
{
    private const string FORMULA_VERSION = '1.0';

    public function __construct(
        AppInterface $app,
        CompanyInterface $company,
        Carbon $date,
        protected readonly DashboardMetricsAggregatorService $aggregator = new DashboardMetricsAggregatorService(),
    ) {
        parent::__construct($app, $company, $date);
    }

    #[Override]
    protected function aggregate(Carbon $start, Carbon $end): DashboardAggregateRow
    {
        return $this->aggregator->aggregate(
            $this->app,
            $this->company,
            $start,
            $end
        );
    }

    #[Override]
    protected function snapshotValues(object $aggregate): array
    {
        /** @var DashboardAggregateRow $aggregate */
        return [
            'plans_completed' => $aggregate->plans_completed,
            'mistakes_auto_corrected' => $aggregate->mistakes_auto_corrected,
            'mistakes_escalated' => $aggregate->mistakes_escalated,
            'agents_active' => $aggregate->agents_active,
            'time_recovered_hours' => $aggregate->time_recovered_hours,
            'value_delivered_cents' => $aggregate->value_delivered_cents,
            'estimated_human_hours' => $aggregate->estimated_human_hours,
        ];
    }

    #[Override]
    protected function eventMetricPayload(object $aggregate): array
    {
        /** @var DashboardAggregateRow $aggregate */
        return [
            'plans_completed' => $aggregate->plans_completed,
            'mistakes_caught' => $aggregate->mistakes_auto_corrected + $aggregate->mistakes_escalated,
        ];
    }

    #[Override]
    protected function modelClass(): string
    {
        return DashboardMetricsDaily::class;
    }

    #[Override]
    protected function eventType(): string
    {
        return 'dashboard.metrics_rolled_up';
    }

    #[Override]
    protected function cacheClass(): string
    {
        return DashboardMetricsCache::class;
    }

    #[Override]
    protected function formulaVersion(): string
    {
        return self::FORMULA_VERSION;
    }
}
