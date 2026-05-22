<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Pulse\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\NervousSystem\Dashboard\Enums\DashboardPeriodEnum;
use Kanvas\NervousSystem\Dashboard\Support\DashboardPeriodResolver;
use Kanvas\NervousSystem\Pulse\DataTransferObject\PulseAggregateRow;
use Kanvas\NervousSystem\Pulse\DataTransferObject\PulseMetrics;
use Kanvas\NervousSystem\Pulse\Models\PulseMetricsDaily;
use Kanvas\NervousSystem\Pulse\Support\PulseMetricsCache;

/**
 * Reader orchestrator for the Pulse KPI strip. Walks each day in the
 * requested window: snapshot for past, live aggregate for today,
 * fallback to live if a past-day snapshot is missing (cron failure
 * shouldn't blank the dashboard).
 *
 * Period semantics reuse DashboardPeriodResolver — TODAY, YESTERDAY,
 * THIS_WEEK + their prior windows for delta arrows.
 *
 * Cached 1h per (app, company, period); the rollup action busts the
 * tenant's cache after each snapshot write.
 */
class PulseMetricsService
{
    public function __construct(
        protected readonly PulseMetricsAggregator $aggregator = new PulseMetricsAggregator(),
        protected readonly DashboardPeriodResolver $periodResolver = new DashboardPeriodResolver(),
    ) {
    }

    public function compute(
        AppInterface $app,
        CompanyInterface $company,
        DashboardPeriodEnum $period,
    ): PulseMetrics {
        $cacheKey = PulseMetricsCache::key($app->getId(), $company->getId(), $period);

        return Cache::remember(
            $cacheKey,
            PulseMetricsCache::TTL_SECONDS,
            fn (): PulseMetrics => $this->computeFresh($app, $company, $period),
        );
    }

    private function computeFresh(
        AppInterface $app,
        CompanyInterface $company,
        DashboardPeriodEnum $period,
    ): PulseMetrics {
        $window = $this->periodResolver->resolve($period);

        $current = $this->sumWindow(
            $app,
            $company,
            $window['starts_at'],
            $window['ends_at']
        );
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

    private function sumWindow(
        AppInterface $app,
        CompanyInterface $company,
        Carbon $start,
        Carbon $end,
    ): PulseAggregateRow {
        $today = Carbon::now()->startOfDay();
        $total = new PulseAggregateRow();

        foreach ($this->periodResolver->eachDate($start, $end) as $day) {
            if ($day->lt($today)) {
                $total->addRow($this->readPastDay($app, $company, $day));
            } else {
                $total->addRow($this->aggregator->aggregate(
                    $app,
                    $company,
                    $day->copy()->startOfDay(),
                    $day->copy()->endOfDay(),
                ));
            }
        }

        return $total;
    }

    private function readPastDay(
        AppInterface $app,
        CompanyInterface $company,
        Carbon $day,
    ): PulseAggregateRow {
        $snapshot = PulseMetricsDaily::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->whereDate('metric_date', $day->toDateString())
            ->first();

        if ($snapshot !== null) {
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

        return $this->aggregator->aggregate(
            $app,
            $company,
            $day->copy()->startOfDay(),
            $day->copy()->endOfDay(),
        );
    }
}
