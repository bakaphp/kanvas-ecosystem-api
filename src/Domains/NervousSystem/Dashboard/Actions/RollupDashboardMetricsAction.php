<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Dashboard\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Dashboard\Models\DashboardMetricsDaily;
use Kanvas\NervousSystem\Dashboard\Services\DashboardMetricsAggregatorService;
use Kanvas\NervousSystem\Dashboard\Support\DashboardMetricsCache;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event;

/**
 * Snapshot one (app, company, date) tuple. Idempotent — re-running the
 * same triple upserts the existing row. The unique key on
 * (apps_id, companies_id, metric_date) enforces this at the DB level.
 *
 * The aggregator is the single source of truth for the formula; passing
 * the day's [start, end] window makes this action a thin wrapper that
 * just persists the result + emits an audit event.
 */
class RollupDashboardMetricsAction
{
    private const string FORMULA_VERSION = '1.0';

    public function __construct(
        protected readonly AppInterface $app,
        protected readonly CompanyInterface $company,
        protected readonly Carbon $date,
        protected readonly DashboardMetricsAggregatorService $aggregator = new DashboardMetricsAggregatorService(),
    ) {
    }

    public function execute(): DashboardMetricsDaily
    {
        $start = $this->date->copy()->startOfDay();
        $end = $this->date->copy()->endOfDay();

        $aggregate = $this->aggregator->aggregate($this->app, $this->company, $start, $end);

        $attributes = [
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
            'metric_date' => $start->toDateString(),
        ];

        $values = [
            'plans_completed' => $aggregate->plans_completed,
            'mistakes_auto_corrected' => $aggregate->mistakes_auto_corrected,
            'mistakes_escalated' => $aggregate->mistakes_escalated,
            'agents_active' => $aggregate->agents_active,
            'time_recovered_hours' => $aggregate->time_recovered_hours,
            'value_delivered_cents' => $aggregate->value_delivered_cents,
            'estimated_human_hours' => $aggregate->estimated_human_hours,
            'computed_at' => Carbon::now(),
            'formula_version' => self::FORMULA_VERSION,
        ];

        $snapshot = DB::connection('intelligence')->transaction(function () use ($attributes, $values): DashboardMetricsDaily {
            return DashboardMetricsDaily::updateOrCreate($attributes, $values);
        });

        new AppendEventAction(
            new Event(
                app: $this->app,
                company: $this->company,
                sourceDomain: 'NervousSystem',
                eventType: 'dashboard.metrics_rolled_up',
                sourceEntityType: DashboardMetricsDaily::class,
                sourceEntityId: $snapshot->id,
                actorType: 'System',
                payload: [
                    'metric_date' => $start->toDateString(),
                    'plans_completed' => $aggregate->plans_completed,
                    'mistakes_caught' => $aggregate->mistakes_auto_corrected + $aggregate->mistakes_escalated,
                    'formula_version' => self::FORMULA_VERSION,
                ],
            ),
        )->execute();

        // Bust cached metric responses for this tenant so the next read
        // sees the freshly-rolled-up day instead of waiting for TTL.
        DashboardMetricsCache::forget($this->app->getId(), $this->company->getId());

        return $snapshot;
    }
}
