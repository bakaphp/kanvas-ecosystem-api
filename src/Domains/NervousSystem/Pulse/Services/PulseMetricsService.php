<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Pulse\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\NervousSystem\Dashboard\Enums\DashboardPeriodEnum;
use Kanvas\NervousSystem\Dashboard\Support\DashboardPeriodResolver;
use Kanvas\NervousSystem\Metrics\Services\AbstractMetricsReaderService;
use Kanvas\NervousSystem\Pulse\DataTransferObject\PulseAggregateRow;
use Kanvas\NervousSystem\Pulse\DataTransferObject\PulseMetrics;
use Kanvas\NervousSystem\Pulse\Models\PulseMetricsDaily;
use Kanvas\NervousSystem\Pulse\Support\PulseMetricsCache;
use Override;

/**
 * Reader orchestrator for the Pulse KPI strip. The cache-remember +
 * day-by-day snapshot/live walk lives in AbstractMetricsReaderService;
 * this class supplies the Pulse aggregator, model, cache, and the final
 * PulseMetrics assembly (including the confidence rounding).
 */
class PulseMetricsService extends AbstractMetricsReaderService
{
    public function __construct(
        protected readonly PulseMetricsAggregatorService $aggregator = new PulseMetricsAggregatorService(),
        DashboardPeriodResolver $periodResolver = new DashboardPeriodResolver(),
    ) {
        parent::__construct($periodResolver);
    }

    #[Override]
    protected function computeFresh(
        AppInterface $app,
        CompanyInterface $company,
        DashboardPeriodEnum $period,
    ): PulseMetrics {
        $window = $this->periodResolver->resolve($period);

        /** @var PulseAggregateRow $current */
        $current = $this->sumWindow(
            $app,
            $company,
            $window['starts_at'],
            $window['ends_at']
        );
        /** @var PulseAggregateRow $prior */
        $prior = $this->sumWindow(
            $app,
            $company,
            $window['prior_starts_at'],
            $window['prior_ends_at']
        );

        return new PulseMetrics(
            signals_count: $current->signals_count,
            signals_count_prior: $prior->signals_count,
            actions_executed: $current->actions_executed,
            actions_executed_prior: $prior->actions_executed,
            prevented_issues: $current->prevented_issues,
            prevented_issues_prior: $prior->prevented_issues,
            system_confidence_pct: $current->system_confidence_pct !== null
                ? round($current->system_confidence_pct, 2)
                : null,
            system_confidence_pct_prior: $prior->system_confidence_pct !== null
                ? round($prior->system_confidence_pct, 2)
                : null,
        );
    }

    #[Override]
    protected function newAggregateRow(): PulseAggregateRow
    {
        return new PulseAggregateRow();
    }

    #[Override]
    protected function aggregateLive(
        AppInterface $app,
        CompanyInterface $company,
        Carbon $start,
        Carbon $end,
    ): PulseAggregateRow {
        return $this->aggregator->aggregate(
            $app,
            $company,
            $start,
            $end
        );
    }

    #[Override]
    protected function modelClass(): string
    {
        return PulseMetricsDaily::class;
    }

    #[Override]
    protected function fromSnapshot(Model $snapshot): PulseAggregateRow
    {
        /** @var PulseMetricsDaily $snapshot */
        return new PulseAggregateRow(
            signals_count: (int) $snapshot->signals_count,
            understand_count: (int) $snapshot->understand_count,
            decide_count: (int) $snapshot->decide_count,
            actions_executed: (int) $snapshot->actions_executed,
            warnings_count: (int) $snapshot->warnings_count,
            prevented_issues: (int) $snapshot->prevented_issues,
            system_confidence_pct: $snapshot->system_confidence_pct,
            // basis_count is lost on snapshot read; treat as 1 so the
            // weighted-average across days still gives reasonable
            // approximations across snapshot+live merges within a week.
            confidence_basis_count: $snapshot->system_confidence_pct !== null ? 1 : 0,
        );
    }

    #[Override]
    protected function cacheClass(): string
    {
        return PulseMetricsCache::class;
    }
}
