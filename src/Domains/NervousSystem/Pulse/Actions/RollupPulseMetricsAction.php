<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Pulse\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event;
use Kanvas\NervousSystem\Pulse\Models\PulseMetricsDaily;
use Kanvas\NervousSystem\Pulse\Services\PulseMetricsAggregator;
use Kanvas\NervousSystem\Pulse\Support\PulseMetricsCache;

/**
 * Snapshot one (app, company, date) tuple of Pulse metrics. Idempotent
 * — re-running upserts the existing row. Single source of truth for
 * the formula via PulseMetricsAggregator.
 *
 * Emits `pulse.metrics_rolled_up` ledger event for audit + flushes the
 * tenant cache so the next dashboard read sees fresh numbers.
 */
class RollupPulseMetricsAction
{
    private const string FORMULA_VERSION = '1.0';

    public function __construct(
        protected readonly AppInterface $app,
        protected readonly CompanyInterface $company,
        protected readonly Carbon $date,
        protected readonly PulseMetricsAggregator $aggregator = new PulseMetricsAggregator(),
    ) {
    }

    public function execute(): PulseMetricsDaily
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
            'signals_count' => $aggregate->signals_count,
            'understand_count' => $aggregate->understand_count,
            'decide_count' => $aggregate->decide_count,
            'actions_executed' => $aggregate->actions_executed,
            'warnings_count' => $aggregate->warnings_count,
            'prevented_issues' => $aggregate->prevented_issues,
            'system_confidence_pct' => $aggregate->system_confidence_pct,
            'computed_at' => Carbon::now(),
            'formula_version' => self::FORMULA_VERSION,
        ];

        $snapshot = DB::connection('intelligence')->transaction(function () use ($attributes, $values): PulseMetricsDaily {
            return PulseMetricsDaily::updateOrCreate($attributes, $values);
        });

        new AppendEventAction(
            new Event(
                app: $this->app,
                company: $this->company,
                sourceDomain: 'NervousSystem',
                eventType: 'pulse.metrics_rolled_up',
                sourceEntityType: PulseMetricsDaily::class,
                sourceEntityId: $snapshot->id,
                actorType: 'System',
                payload: [
                    'metric_date' => $start->toDateString(),
                    'signals_count' => $aggregate->signals_count,
                    'actions_executed' => $aggregate->actions_executed,
                    'prevented_issues' => $aggregate->prevented_issues,
                    'formula_version' => self::FORMULA_VERSION,
                ],
            ),
        )->execute();

        PulseMetricsCache::forget($this->app->getId(), $this->company->getId());

        return $snapshot;
    }
}
