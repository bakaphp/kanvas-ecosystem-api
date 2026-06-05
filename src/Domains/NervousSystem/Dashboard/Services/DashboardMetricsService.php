<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Dashboard\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\NervousSystem\Dashboard\DataTransferObject\DashboardAggregateRow;
use Kanvas\NervousSystem\Dashboard\DataTransferObject\DashboardMetrics;
use Kanvas\NervousSystem\Dashboard\Enums\DashboardPeriodEnum;
use Kanvas\NervousSystem\Dashboard\Models\DashboardMetricsDaily;
use Kanvas\NervousSystem\Dashboard\Support\DashboardMetricsCache;
use Kanvas\NervousSystem\Dashboard\Support\DashboardPeriodResolver;

/**
 * Reader orchestrator. Walks each day in the requested window and
 * picks the cheaper source per day:
 *   - past day with snapshot row → read snapshot (1 SELECT)
 *   - past day without snapshot → live aggregate (cron didn't run; warn)
 *   - today → live aggregate (always — snapshot doesn't exist yet)
 *
 * Sums those into the current/prior totals, then computes derived
 * fields (workforce_leverage, human_equivalents) from the aggregates.
 */
class DashboardMetricsService
{
    private const float HOURS_PER_WORKDAY = 8.0;

    public function __construct(
        protected readonly DashboardMetricsAggregatorService $aggregator = new DashboardMetricsAggregatorService(),
        protected readonly DashboardPeriodResolver $periodResolver = new DashboardPeriodResolver(),
    ) {
    }

    public function compute(
        AppInterface $app,
        CompanyInterface $company,
        DashboardPeriodEnum $period,
    ): DashboardMetrics {
        $cacheKey = DashboardMetricsCache::key($app->getId(), $company->getId(), $period);

        return Cache::remember(
            $cacheKey,
            DashboardMetricsCache::TTL_SECONDS,
            fn (): DashboardMetrics => $this->computeFresh($app, $company, $period),
        );
    }

    private function computeFresh(
        AppInterface $app,
        CompanyInterface $company,
        DashboardPeriodEnum $period,
    ): DashboardMetrics {
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

        return new DashboardMetrics(
            workforce_leverage: $this->leverage($current),
            workforce_leverage_prior: $this->leverage($prior),
            human_equivalents: $this->humanEquivalents($current),
            accomplishments_count: $current->plans_completed,
            accomplishments_count_prior: $prior->plans_completed,
            mistakes_caught_count: $current->mistakes_auto_corrected + $current->mistakes_escalated,
            mistakes_auto_corrected: $current->mistakes_auto_corrected,
            mistakes_escalated: $current->mistakes_escalated,
            time_recovered_hours: $current->time_recovered_hours,
            time_recovered_hours_prior: $prior->time_recovered_hours,
            value_delivered_cents: $current->value_delivered_cents,
            value_delivered_cents_prior: $prior->value_delivered_cents,
        );
    }

    private function sumWindow(
        AppInterface $app,
        CompanyInterface $company,
        Carbon $start,
        Carbon $end,
    ): DashboardAggregateRow {
        $today = Carbon::now()->startOfDay();
        $total = new DashboardAggregateRow();

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
    ): DashboardAggregateRow {
        $snapshot = DashboardMetricsDaily::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->whereDate('metric_date', $day->toDateString())
            ->first();

        if ($snapshot !== null) {
            return new DashboardAggregateRow(
                plans_completed: (int) $snapshot->plans_completed,
                mistakes_auto_corrected: (int) $snapshot->mistakes_auto_corrected,
                mistakes_escalated: (int) $snapshot->mistakes_escalated,
                agents_active: (int) $snapshot->agents_active,
                time_recovered_hours: $snapshot->time_recovered_hours,
                value_delivered_cents: $snapshot->value_delivered_cents,
                estimated_human_hours: $snapshot->estimated_human_hours,
            );
        }

        return $this->aggregator->aggregate(
            $app,
            $company,
            $day->copy()->startOfDay(),
            $day->copy()->endOfDay(),
        );
    }

    /**
     * Workforce leverage = how many human-equivalent hours of work the
     * agents replaced, divided by how many agent-workdays were spent.
     * Returns null when output.estimated_human_hours isn't populated yet.
     */
    private function leverage(DashboardAggregateRow $row): ?float
    {
        if ($row->estimated_human_hours === null || $row->agents_active === 0) {
            return null;
        }

        $agentHours = max($row->agents_active * self::HOURS_PER_WORKDAY, 1.0);

        return round($row->estimated_human_hours / $agentHours, 2);
    }

    /**
     * Human equivalents = estimated_human_hours / 8h workday.
     * Returns null when output.estimated_human_hours isn't populated yet.
     */
    private function humanEquivalents(DashboardAggregateRow $row): ?float
    {
        if ($row->estimated_human_hours === null) {
            return null;
        }

        return round($row->estimated_human_hours / self::HOURS_PER_WORKDAY, 1);
    }
}
