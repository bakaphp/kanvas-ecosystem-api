<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Dashboard\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\NervousSystem\Dashboard\DataTransferObject\DashboardAggregateRow;
use Kanvas\NervousSystem\Dashboard\DataTransferObject\DashboardMetrics;
use Kanvas\NervousSystem\Dashboard\Enums\DashboardPeriodEnum;
use Kanvas\NervousSystem\Dashboard\Models\DashboardMetricsDaily;
use Kanvas\NervousSystem\Dashboard\Support\DashboardMetricsCache;
use Kanvas\NervousSystem\Dashboard\Support\DashboardPeriodResolver;
use Kanvas\NervousSystem\Metrics\Services\AbstractMetricsReaderService;
use Override;

/**
 * Reader orchestrator. The cache-remember + day-by-day snapshot/live
 * walk lives in AbstractMetricsReaderService; this class supplies the
 * Dashboard aggregator, model, cache, and the derived-fields assembly
 * (workforce_leverage, human_equivalents).
 */
class DashboardMetricsService extends AbstractMetricsReaderService
{
    private const float HOURS_PER_WORKDAY = 8.0;

    public function __construct(
        protected readonly DashboardMetricsAggregatorService $aggregator = new DashboardMetricsAggregatorService(),
        DashboardPeriodResolver $periodResolver = new DashboardPeriodResolver(),
    ) {
        parent::__construct($periodResolver);
    }

    #[Override]
    protected function computeFresh(
        AppInterface $app,
        CompanyInterface $company,
        DashboardPeriodEnum $period,
    ): DashboardMetrics {
        $window = $this->periodResolver->resolve($period);

        /** @var DashboardAggregateRow $current */
        $current = $this->sumWindow(
            $app,
            $company,
            $window['starts_at'],
            $window['ends_at']
        );
        /** @var DashboardAggregateRow $prior */
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

    #[Override]
    protected function newAggregateRow(): DashboardAggregateRow
    {
        return new DashboardAggregateRow();
    }

    #[Override]
    protected function aggregateLive(
        AppInterface $app,
        CompanyInterface $company,
        Carbon $start,
        Carbon $end,
    ): DashboardAggregateRow {
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
        return DashboardMetricsDaily::class;
    }

    #[Override]
    protected function fromSnapshot(Model $snapshot): DashboardAggregateRow
    {
        /** @var DashboardMetricsDaily $snapshot */
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

    #[Override]
    protected function cacheClass(): string
    {
        return DashboardMetricsCache::class;
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
