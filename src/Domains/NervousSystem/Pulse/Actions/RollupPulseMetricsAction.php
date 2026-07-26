<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Pulse\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\NervousSystem\Metrics\Actions\AbstractRollupMetricsAction;
use Kanvas\NervousSystem\Pulse\DataTransferObject\PulseAggregateRow;
use Kanvas\NervousSystem\Pulse\Models\PulseMetricsDaily;
use Kanvas\NervousSystem\Pulse\Services\PulseMetricsAggregatorService;
use Kanvas\NervousSystem\Pulse\Support\PulseMetricsCache;
use Override;

/**
 * Snapshot one (app, company, date) tuple of Pulse metrics. The
 * idempotent-upsert + ledger-emit + cache-bust shape lives in
 * AbstractRollupMetricsAction; this class supplies only what's
 * Pulse-specific — the aggregator, snapshot columns, event type/payload,
 * model, and cache.
 */
class RollupPulseMetricsAction extends AbstractRollupMetricsAction
{
    private const string FORMULA_VERSION = '1.0';

    public function __construct(
        AppInterface $app,
        CompanyInterface $company,
        Carbon $date,
        protected readonly PulseMetricsAggregatorService $aggregator = new PulseMetricsAggregatorService(),
    ) {
        parent::__construct($app, $company, $date);
    }

    #[Override]
    protected function aggregate(Carbon $start, Carbon $end): PulseAggregateRow
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
        /** @var PulseAggregateRow $aggregate */
        return [
            'signals_count' => $aggregate->signals_count,
            'understand_count' => $aggregate->understand_count,
            'decide_count' => $aggregate->decide_count,
            'actions_executed' => $aggregate->actions_executed,
            'warnings_count' => $aggregate->warnings_count,
            'prevented_issues' => $aggregate->prevented_issues,
            'system_confidence_pct' => $aggregate->system_confidence_pct,
        ];
    }

    #[Override]
    protected function eventMetricPayload(object $aggregate): array
    {
        /** @var PulseAggregateRow $aggregate */
        return [
            'signals_count' => $aggregate->signals_count,
            'actions_executed' => $aggregate->actions_executed,
            'prevented_issues' => $aggregate->prevented_issues,
        ];
    }

    #[Override]
    protected function modelClass(): string
    {
        return PulseMetricsDaily::class;
    }

    #[Override]
    protected function eventType(): string
    {
        return 'pulse.metrics_rolled_up';
    }

    #[Override]
    protected function cacheClass(): string
    {
        return PulseMetricsCache::class;
    }

    #[Override]
    protected function formulaVersion(): string
    {
        return self::FORMULA_VERSION;
    }
}
