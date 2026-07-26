<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Metrics\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event;
use Kanvas\NervousSystem\Metrics\Support\AbstractMetricsCache;
use Kanvas\NervousSystem\Models\ImmutableBaseModel;

/**
 * Shared shape for the Pulse/Dashboard daily rollup writers: aggregate a
 * (app, company, date) window, upsert the snapshot row, emit an audit
 * ledger event, and bust the tenant's cache.
 *
 * Idempotent — re-running the same triple upserts the existing row. A
 * concurrent rollup can insert the row between our SELECT and INSERT
 * (UniqueConstraintViolationException); the catch below falls back to
 * an UPDATE so both writers converge on the same row instead of one
 * bubbling a 500 (Sentry KANVAS-ECOSYSTEM-5MG).
 *
 * Subclasses supply the metric-specific pieces: aggregation source,
 * snapshot columns, event type/payload, model, and cache class. The
 * formula version constant stays on each subclass since it versions
 * that surface's formula independently.
 */
abstract class AbstractRollupMetricsAction
{
    public function __construct(
        protected readonly AppInterface $app,
        protected readonly CompanyInterface $company,
        protected readonly Carbon $date,
    ) {
    }

    public function execute(): ImmutableBaseModel
    {
        $start = $this->date->copy()->startOfDay();
        $end = $this->date->copy()->endOfDay();

        $aggregate = $this->aggregate($start, $end);

        $attributes = [
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
            'metric_date' => $start->toDateString(),
        ];

        $values = array_merge(
            $this->snapshotValues($aggregate),
            [
                'computed_at' => Carbon::now(),
                'formula_version' => $this->formulaVersion(),
            ],
        );

        $modelClass = $this->modelClass();

        $snapshot = DB::connection($this->connectionName())->transaction(function () use ($attributes, $values, $modelClass): ImmutableBaseModel {
            try {
                return $modelClass::updateOrCreate($attributes, $values);
            } catch (UniqueConstraintViolationException) {
                // A concurrent rollup inserted this (app, company, date) row
                // between our SELECT and INSERT. The row exists now — update it.
                $snapshot = $modelClass::query()->where($attributes)->firstOrFail();
                $snapshot->update($values);

                return $snapshot;
            }
        });

        new AppendEventAction(
            new Event(
                app: $this->app,
                company: $this->company,
                sourceDomain: 'NervousSystem',
                eventType: $this->eventType(),
                sourceEntityType: $modelClass,
                sourceEntityId: $snapshot->id,
                actorType: 'System',
                payload: array_merge(
                    ['metric_date' => $start->toDateString()],
                    $this->eventMetricPayload($aggregate),
                    ['formula_version' => $this->formulaVersion()],
                ),
            ),
        )->execute();

        $cacheClass = $this->cacheClass();
        $cacheClass::forget($this->app->getId(), $this->company->getId());

        return $snapshot;
    }

    protected function connectionName(): string
    {
        return 'intelligence';
    }

    abstract protected function aggregate(Carbon $start, Carbon $end): object;

    /**
     * Metric columns only — computed_at and formula_version are appended
     * by execute().
     *
     * @return array<string, mixed>
     */
    abstract protected function snapshotValues(object $aggregate): array;

    /**
     * Metric-specific ledger payload keys — metric_date and
     * formula_version are appended by execute().
     *
     * @return array<string, mixed>
     */
    abstract protected function eventMetricPayload(object $aggregate): array;

    /** @return class-string<ImmutableBaseModel> */
    abstract protected function modelClass(): string;

    abstract protected function eventType(): string;

    /** @return class-string<AbstractMetricsCache> */
    abstract protected function cacheClass(): string;

    abstract protected function formulaVersion(): string;
}
