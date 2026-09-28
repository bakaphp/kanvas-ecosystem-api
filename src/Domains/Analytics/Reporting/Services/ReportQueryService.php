<?php

declare(strict_types=1);

namespace Kanvas\Analytics\Reporting\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Kanvas\Analytics\Reporting\Contracts\ReportDefinitionInterface;
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
        int $offset = 0
    ): array {
        $columns = $this->columnsByName($definition);

        if ($select !== null) {
            foreach ($select as $name) {
                $this->assertColumn($name, $columns);
            }
        }

        return $this->builder($definition, $app, $company, $filters)
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
