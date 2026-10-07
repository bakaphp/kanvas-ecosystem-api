<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Reporting\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Kanvas\Analytics\Reporting\Contracts\ReportDefinitionInterface;
use Kanvas\Analytics\Reporting\DataTransferObject\AggregateRequest;
use Kanvas\Analytics\Reporting\DataTransferObject\ReportColumn;
use Kanvas\Analytics\Reporting\DataTransferObject\ReportFilter;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;

/**
 * Compiles filters into a query against a flat table.
 *
 * Two invariants, both structural rather than conventional:
 *
 * - **The app is never a predicate.** It is in the table name, and the table name comes from the
 *   definition, never from caller input — so there is no app filter to forget.
 * - **The company predicate is added here**, before any caller-supplied condition, and there is
 *   no way to ask for a query without it. Same guarantee `BuildAnalyticsAction` gives.
 *
 * Every column referenced is checked against the definition, so a filter cannot reach a column
 * that is not declared — which is also what keeps agent-supplied input off the raw schema.
 */
class ReportQueryService
{
    public const int MAX_LIMIT = 1000;

    /**
     * Date buckets a `group_by` entry can ask for as `column:bucket`. Every key renders a sortable
     * string, so ordering the bucket ascending is ordering it in time.
     */
    public const array DATE_BUCKETS = [
        'day' => "DATE_FORMAT(`%1\$s`, '%%Y-%%m-%%d')",
        'month' => "DATE_FORMAT(`%1\$s`, '%%Y-%%m')",
        'quarter' => "CONCAT(YEAR(`%1\$s`), '-Q', QUARTER(`%1\$s`))",
        'year' => 'CAST(YEAR(`%1$s`) AS CHAR)',
    ];

    private const array DATE_TYPES = ['date', 'datetime'];

    public function __construct(
        protected ReportSchemaService $schema = new ReportSchemaService(),
    ) {
    }

    /**
     * @param array<int, ReportFilter> $filters
     */
    public function builder(
        ReportDefinitionInterface $definition,
        AppInterface $app,
        Companies $company,
        array $filters = []
    ): Builder {
        $query = $this->schema->connection()
            ->table($this->schema->tableFor($definition, $app->getId()))
            ->where(ReportSchemaService::TENANT_COLUMN, $company->getId());

        $columns = $this->columnsByName($definition);

        foreach ($filters as $filter) {
            $this->apply($query, $filter, $columns);
        }

        return $query;
    }

    /**
     * @param array<int, ReportFilter> $filters
     * @param array<int, string>|null $select
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(
        ReportDefinitionInterface $definition,
        AppInterface $app,
        Companies $company,
        array $filters = [],
        ?array $select = null,
        int $limit = 50,
        int $offset = 0,
        ?string $orderBy = null,
        bool $descending = false
    ): array {
        $columns = $this->columnsByName($definition);

        if ($select !== null) {
            foreach ($select as $name) {
                $this->assertColumn($name, $columns);
            }
        }

        $query = $this->builder($definition, $app, $company, $filters);

        if ($orderBy !== null) {
            $this->assertColumn($orderBy, $columns);
            $query->orderBy($orderBy, $descending ? 'desc' : 'asc');
        }

        // Tie-break on the key so paging through equal sort values never repeats or skips a row.
        return $query->orderBy($definition->primaryKey())
            ->select($select ?? ['*'])
            ->limit(min($limit, self::MAX_LIMIT))
            ->offset($offset)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * Counting rows is rarely the question. At a person × event grain a row is a registration,
     * so "how many ejecutivos" needs COUNT(DISTINCT) — which is exactly the distinction two of
     * SIPGO's own reports get wrong in opposite directions.
     *
     * @param array<int, ReportFilter> $filters
     *
     * @return array{rows: int, distinct: int|null}
     */
    public function count(
        ReportDefinitionInterface $definition,
        AppInterface $app,
        Companies $company,
        array $filters = [],
        ?string $distinctColumn = null
    ): array {
        $rows = $this->builder($definition, $app, $company, $filters)->count();

        $distinct = null;

        if ($distinctColumn !== null) {
            $this->assertColumn($distinctColumn, $this->columnsByName($definition));
            $distinct = $this->builder($definition, $app, $company, $filters)
                ->distinct()
                ->count($distinctColumn);
        }

        return ['rows' => $rows, 'distinct' => $distinct];
    }

    /**
     * Group-by for the charts.
     *
     * The count alias is `total`, not `rows` — `rows` is reserved in MySQL 8.
     *
     * @param array<int, ReportFilter> $filters
     *
     * @return array<int, array<string, mixed>>
     */
    public function breakdown(
        ReportDefinitionInterface $definition,
        AppInterface $app,
        Companies $company,
        string $groupBy,
        array $filters = [],
        ?string $distinctColumn = null
    ): array {
        $columns = $this->columnsByName($definition);
        $this->assertColumn($groupBy, $columns);

        $query = $this->builder($definition, $app, $company, $filters)
            ->groupBy($groupBy)
            ->orderByDesc('total');

        $select = [$groupBy, DB::raw('COUNT(*) as `total`')];

        if ($distinctColumn !== null) {
            $this->assertColumn($distinctColumn, $columns);
            $select[] = DB::raw(sprintf('COUNT(DISTINCT `%s`) as `distinct_total`', $distinctColumn));
        }

        return $query->select($select)->get()->map(fn ($row) => (array) $row)->all();
    }

    /**
     * Rank and total — the two things `count()` and `breakdown()` cannot do.
     *
     * "Top 5 eventos por participantes", "promedio de participantes por evento" and every
     * numeric input to the classification sheets need an aggregate and an ordering, and without
     * them a caller has to pull rows and add them up in PHP. Column names are validated exactly
     * as in `apply()`, and the function name is matched against a fixed list, so nothing here
     * widens what caller input can reach.
     *
     * @param array<int, ReportFilter> $filters
     *
     * @return array<int, array<string, mixed>>
     */
    public function aggregate(
        ReportDefinitionInterface $definition,
        AppInterface $app,
        Companies $company,
        AggregateRequest $request,
        array $filters = []
    ): array {
        $columns = $this->columnsByName($definition);
        $query = $this->builder($definition, $app, $company, $filters);

        $select = [];
        $bucketAliases = [];

        foreach ($request->groupBy as $groupColumn) {
            $bucket = $this->dateBucket($groupColumn, $columns);

            if ($bucket === null) {
                $this->assertColumn($groupColumn, $columns);
                $query->groupBy($groupColumn);
                $select[] = $groupColumn;

                continue;
            }

            [$expression, $alias] = $bucket;
            $bucketAliases[$groupColumn] = $alias;
            $bucketAliases[$alias] = $alias;
            $query->groupBy($alias);
            $select[] = DB::raw(sprintf('%s as `%s`', $expression, $alias));
        }

        foreach ($request->aggregates as $alias => $spec) {
            [$function, $column] = $spec;

            // COUNT(*) is the one aggregate with no column to validate.
            if ($column !== null) {
                $this->assertColumn($column, $columns);
            }

            $select[] = DB::raw(match (true) {
                $function === 'COUNT_DISTINCT' => sprintf('COUNT(DISTINCT `%s`) as `%s`', $column, $alias),
                $column === null => sprintf('COUNT(*) as `%s`', $alias),
                default => sprintf('%s(`%s`) as `%s`', $function, $column, $alias),
            });
        }

        if ($select === []) {
            throw new ValidationException('An aggregate query needs at least one grouping or aggregate.');
        }

        if ($request->orderBy !== null) {
            // Ordering by an alias is the whole point — "top 5 by total" — so an alias is
            // accepted, and anything else has to be a declared column.
            $orderBy = $bucketAliases[$request->orderBy] ?? $request->orderBy;

            if (! array_key_exists($orderBy, $request->aggregates) && ! in_array($orderBy, $bucketAliases, true)) {
                $this->assertColumn($orderBy, $columns);
            }

            $query->orderBy($orderBy, $request->descending ? 'desc' : 'asc');
        } elseif ($bucketAliases !== []) {
            // A time series with no explicit order reads left to right in time.
            $query->orderBy(reset($bucketAliases));
        }

        $aliases = array_keys($request->aggregates);

        return $query->select($select)
            ->limit(min($request->limit, self::MAX_LIMIT))
            ->get()
            ->map(fn ($row) => $this->numericAggregates((array) $row, $aliases))
            ->all();
    }

    /**
     * MySQL hands SUM/AVG back as decimal strings; every consumer wants numbers.
     *
     * @param array<string, mixed> $row
     * @param list<string> $aliases
     *
     * @return array<string, mixed>
     */
    protected function numericAggregates(array $row, array $aliases): array
    {
        foreach ($aliases as $alias) {
            if (is_string($row[$alias] ?? null) && is_numeric($row[$alias])) {
                $row[$alias] += 0;
            }
        }

        return $row;
    }

    /**
     * @param array<string, ReportColumn> $columns
     */
    protected function apply(Builder $query, ReportFilter $filter, array $columns): void
    {
        $column = $this->assertColumn($filter->column, $columns);

        match ($filter->operator) {
            'IS NULL' => $query->whereNull($filter->column),
            'IS NOT NULL' => $query->whereNotNull($filter->column),
            'IN' => $query->whereIn($filter->column, (array) $filter->value),
            'NOT IN' => $query->whereNotIn($filter->column, (array) $filter->value),
            'BETWEEN' => $query->whereBetween($filter->column, $this->pair($filter)),
            // The array columns. Bound as a parameter, never interpolated.
            'MEMBER OF' => $query->whereRaw(
                sprintf('CAST(? AS %s) MEMBER OF (`%s`)', $column->indexCast ?? 'CHAR(64)', $filter->column),
                [$filter->value]
            ),
            default => $query->where($filter->column, $filter->operator, $filter->value),
        };
    }

    /**
     * @return array{0: mixed, 1: mixed}
     */
    protected function pair(ReportFilter $filter): array
    {
        $value = (array) $filter->value;

        if (count($value) !== 2) {
            throw new ValidationException('BETWEEN on ' . $filter->column . ' needs exactly two values.');
        }

        return [array_values($value)[0], array_values($value)[1]];
    }

    /**
     * `fecha_inicio:month` → [the SQL that buckets it, `fecha_inicio_month`]; null for a plain column.
     *
     * The column is validated before it reaches the expression and the bucket must be one of the
     * declared keys, so neither half of the string reaches the SQL unchecked.
     *
     * @param array<string, ReportColumn> $columns
     *
     * @return array{0: string, 1: string}|null
     */
    protected function dateBucket(string $groupBy, array $columns): ?array
    {
        if (! str_contains($groupBy, ':')) {
            return null;
        }

        [$name, $bucket] = explode(':', $groupBy, 2);
        $column = $this->assertColumn($name, $columns);

        if (! isset(self::DATE_BUCKETS[$bucket])) {
            throw new ValidationException(sprintf(
                'Unknown date bucket "%s". Use one of: %s.',
                $bucket,
                implode(', ', array_keys(self::DATE_BUCKETS))
            ));
        }

        if (! in_array($column->type, self::DATE_TYPES, true)) {
            throw new ValidationException(sprintf('"%s" is not a date column, so it cannot be grouped by %s.', $name, $bucket));
        }

        return [sprintf(self::DATE_BUCKETS[$bucket], $name), self::groupingKey($groupBy)];
    }

    /**
     * The key a `group_by` entry comes back under: the column itself, or `fecha_inicio_month`
     * for `fecha_inicio:month`.
     */
    public static function groupingKey(string $groupBy): string
    {
        return str_replace(':', '_', $groupBy);
    }

    /**
     * @param array<string, ReportColumn> $columns
     */
    protected function assertColumn(string $name, array $columns): ReportColumn
    {
        if (! isset($columns[$name])) {
            throw new ValidationException(sprintf(
                'Unknown report column "%s". Available: %s',
                $name,
                implode(', ', array_keys($columns))
            ));
        }

        return $columns[$name];
    }

    /**
     * Declared columns plus the three every table carries implicitly — the primary key is not in
     * `columns()` when a definition keys on an id it does not otherwise expose, and filtering or
     * selecting it must still work.
     *
     * @return array<string, ReportColumn>
     */
    protected function columnsByName(ReportDefinitionInterface $definition): array
    {
        $byName = [
            $definition->primaryKey() => ReportColumn::integer($definition->primaryKey()),
            ReportSchemaService::TENANT_COLUMN => ReportColumn::integer(ReportSchemaService::TENANT_COLUMN),
            'refreshed_at' => ReportColumn::datetime('refreshed_at'),
        ];

        foreach ($definition->columns() as $column) {
            $byName[$column->name] = $column;
        }

        return $byName;
    }
}
