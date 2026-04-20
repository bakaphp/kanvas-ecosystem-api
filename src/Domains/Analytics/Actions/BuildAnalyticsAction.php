<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Actions;

use Baka\Contracts\AppInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Analytics\DataTransferObject\AnalyticsGroupBy;
use Kanvas\Analytics\DataTransferObject\AnalyticsRequest;
use Kanvas\Analytics\Support\PeriodFormatter;
use Kanvas\Companies\Models\Companies;

/**
 * Shared analytics aggregation action. Every analytics resolver funnels through this.
 *
 * Returns a standard shape:
 *  [
 *    'periods'     => [['period' => 'YYYY-...', 'count' => int, 'total' => float|null], ...],
 *    'total'       => int,
 *    'total_value' => float|null,            // present only when sumColumn is set
 *    '<by_*>'      => [['name' => ..., 'key' => ..., 'count' => int, 'total' => float|null], ...],
 *  ]
 *
 * Multi-tenancy scoping (apps_id + companies_id + is_deleted) is applied FIRST, before any
 * caller-supplied scope — it is architecturally impossible to bypass. See
 * docs/unified-analytics-queries-plan.md §5.3.
 */
class BuildAnalyticsAction
{
    /**
     * @param  class-string<Model>  $model  Eloquent model class to aggregate over.
     * @param  string  $createdAtColumn  Timestamp column used for bucketing (defaults to `created_at`).
     * @param  array<string, AnalyticsGroupBy>  $groupBys  Map of output field name → group-by spec.
     *                                                   Example: ['by_status' => new AnalyticsGroupBy('leads_status_id', ...)]
     * @param  string|null  $sumColumn  Column to sum for `total_value` and per-period `total`. Null = count-only.
     * @param  Closure|null  $extraScopes  Optional callable(Builder $query): Builder — applied AFTER tenant scope.
     */
    public function __construct(
        protected readonly string $model,
        protected readonly AppInterface $app,
        protected readonly Companies $company,
        protected readonly AnalyticsRequest $request,
        protected readonly array $groupBys = [],
        protected readonly ?string $sumColumn = null,
        protected readonly ?Closure $extraScopes = null,
        protected readonly string $createdAtColumn = 'created_at',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $periods = $this->periods();
        $total = $this->total();

        $result = [
            'periods' => $periods,
            'total' => $total,
        ];

        if ($this->sumColumn !== null) {
            $result['total_value'] = (float) array_sum(array_map(
                static fn (array $row): float => (float) ($row['total'] ?? 0.0),
                $periods,
            ));
        }

        foreach ($this->groupBys as $outputKey => $groupBy) {
            $result[$outputKey] = $this->categoryBreakdown($groupBy);
        }

        return $result;
    }

    /**
     * Build a fresh base query with tenant scope + date range + extra scopes applied.
     * ALWAYS filters apps_id, companies_id, is_deleted. Non-negotiable.
     */
    protected function baseQuery(): Builder
    {
        /** @var Model $instance */
        $instance = new $this->model();
        $query = $instance->newQuery();

        $table = $instance->getTable();

        $query->where("{$table}.apps_id", $this->app->getId());
        $query->where("{$table}.companies_id", $this->company->getId());
        $query->where("{$table}.is_deleted", 0);

        $query->whereBetween(
            "{$table}.{$this->createdAtColumn}",
            [$this->request->from->copy()->utc(), $this->request->to->copy()->utc()],
        );

        if ($this->extraScopes !== null) {
            ($this->extraScopes)($query);
        }

        return $query;
    }

    /**
     * @return array<int, array{period: string, count: int, total: float|null}>
     */
    protected function periods(): array
    {
        $query = $this->baseQuery();
        /** @var Model $instance */
        $instance = new $this->model();
        $table = $instance->getTable();

        $formatted = PeriodFormatter::bucketExpression(
            "{$table}.{$this->createdAtColumn}",
            $this->request->bucket,
            $this->request->timezone,
        );

        $selects = [
            "{$formatted['sql']} as period",
            'COUNT(*) as aggregate_count',
        ];
        $bindings = $formatted['bindings'];

        if ($this->sumColumn !== null) {
            $selects[] = 'COALESCE(SUM(`' . str_replace('`', '', $this->sumColumn) . '`), 0) as aggregate_total';
        }

        $rows = $query
            ->selectRaw(implode(', ', $selects), $bindings)
            ->groupBy('period')
            ->orderBy('period', 'asc')
            ->get();

        return $rows->map(fn ($row): array => [
            'period' => (string) $row->period,
            'count' => (int) $row->aggregate_count,
            'total' => $this->sumColumn !== null ? (float) $row->aggregate_total : null,
        ])->all();
    }

    protected function total(): int
    {
        return (int) $this->baseQuery()->count();
    }

    /**
     * @return array<int, array{name: string, key: string|null, count: int, total: float|null}>
     */
    protected function categoryBreakdown(AnalyticsGroupBy $groupBy): array
    {
        $query = $this->baseQuery();
        /** @var Model $instance */
        $instance = new $this->model();
        $table = $instance->getTable();
        $quotedColumn = '`' . str_replace('`', '', $table) . '`.`' . str_replace('`', '', $groupBy->column) . '`';

        $sumColumn = $groupBy->sumColumn ?? $this->sumColumn;

        $selects = [
            "{$quotedColumn} as category_key",
            'COUNT(*) as aggregate_count',
        ];

        if ($sumColumn !== null) {
            $selects[] = 'COALESCE(SUM(`' . str_replace('`', '', $sumColumn) . '`), 0) as aggregate_total';
        }

        $rows = $query
            ->selectRaw(implode(', ', $selects))
            ->groupBy("{$table}.{$groupBy->column}")
            ->orderByDesc('aggregate_count')
            ->get();

        $labelMap = $this->resolveLabels($groupBy, $rows->pluck('category_key')->all());

        return $rows->map(function ($row) use ($groupBy, $sumColumn, $labelMap): array {
            $key = $row->category_key;
            $keyString = $key === null ? null : (string) $key;

            return [
                'name' => $this->labelFor($groupBy, $key, $labelMap),
                'key' => $keyString,
                'count' => (int) $row->aggregate_count,
                'total' => $sumColumn !== null ? (float) $row->aggregate_total : null,
            ];
        })->all();
    }

    /**
     * @param  array<int, mixed>  $keys
     * @return array<string, string>
     */
    protected function resolveLabels(AnalyticsGroupBy $groupBy, array $keys): array
    {
        if ($groupBy->relation === null || $groupBy->labelColumn === null) {
            return [];
        }

        $nonNullKeys = array_values(array_filter($keys, static fn ($k) => $k !== null));
        if ($nonNullKeys === []) {
            return [];
        }

        /** @var Model $instance */
        $instance = new $this->model();
        $relation = $instance->{$groupBy->relation}();
        $related = $relation->getRelated();
        $ownerKey = $relation->getOwnerKeyName();

        $relatedRows = $related->newQuery()
            ->whereIn($ownerKey, $nonNullKeys)
            ->get([$ownerKey, $groupBy->labelColumn]);

        $map = [];
        foreach ($relatedRows as $relatedRow) {
            $map[(string) $relatedRow->{$ownerKey}] = (string) ($relatedRow->{$groupBy->labelColumn} ?? '');
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $labelMap
     */
    protected function labelFor(AnalyticsGroupBy $groupBy, mixed $key, array $labelMap): string
    {
        if ($groupBy->labelResolver !== null) {
            return (string) ($groupBy->labelResolver)($key);
        }

        if ($key === null) {
            return '—';
        }

        $keyString = (string) $key;

        return $labelMap[$keyString] ?? $keyString;
    }
}
