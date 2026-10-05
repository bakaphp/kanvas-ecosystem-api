<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Reporting;

use Baka\Support\Str;
use Kanvas\Analytics\Reporting\DataTransferObject\AggregateRequest;
use Kanvas\Analytics\Reporting\DataTransferObject\ReportFilter;
use Kanvas\Analytics\Reporting\Services\ReportQueryService;
use Kanvas\Analytics\Reporting\Support\ReportRegistry;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Query a flat reporting table.
 *
 * The agent supplies filters, never SQL. Column names are validated against the definition and
 * the company predicate is injected by the query service, so neither tenant scope nor the schema
 * is reachable from tool input.
 *
 * Rows and distinct counts are both returned because at a person × event grain they answer
 * different questions — counting rows counts registrations, not people.
 *
 * The breakdown runs on the same `aggregate()` the `reportAggregate` GraphQL query uses, so the
 * agent and the reports screen can answer exactly the same questions. With no `aggregates` each
 * group carries `total` (and `distinct_total` with `distinct_column`), the shape existing prompts
 * read.
 */
#[AgentTool(name: 'Run Report', category: 'reporting')]
class RunReportTool extends Tool
{
    use TrackByInputs;
    use HasKanvasContext;

    protected string $name = 'run_report';

    protected ?string $description = 'Query a reporting model with filters and get back rows plus counts. '
        . 'Call describe_report_model first to get the column names. Filters are ANDed. '
        . 'On a person-per-event model, set distinct_column to count people rather than '
        . 'registrations. Set group_by to get a breakdown for a chart instead of rows; it '
        . 'takes several columns and date periods ("fecha_inicio:month"). Add aggregates '
        . 'to sum, average, min or max a column (revenue, satisfaction) per group.';

    private const int DEFAULT_GROUP_LIMIT = 200;

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'model',
                type: PropertyType::STRING,
                description: 'Model name from describe_report_model, e.g. "ejecutivo".',
                required: true,
            ),
            // ArrayProperty, not a bare PropertyType::ARRAY: Gemini requires `items` on every
            // array and rejects the whole tool list without it — one malformed declaration takes
            // down every other tool in the turn, not just this one.
            new ArrayProperty(
                name: 'filters',
                description: 'Conditions, ANDed.',
                required: false,
                items: new ObjectProperty(
                    name: 'filter',
                    description: 'One condition, e.g. {"column":"nivel","operator":"=","value":"Gerencial"}.',
                    properties: [
                        new ToolProperty(
                            name: 'column',
                            type: PropertyType::STRING,
                            description: 'Column name exactly as describe_report_model lists it.',
                            required: true,
                        ),
                        new ToolProperty(
                            name: 'operator',
                            type: PropertyType::STRING,
                            description: 'MEMBER OF works on multi-valued columns only — check '
                                . 'multi_valued in describe_report_model.',
                            required: true,
                            enum: ReportFilter::OPERATORS,
                        ),
                        new ToolProperty(
                            name: 'value',
                            type: PropertyType::STRING,
                            description: 'What to compare against. Omit for IS NULL / IS NOT NULL. For '
                                . 'IN, NOT IN and BETWEEN pass a comma-separated list, e.g. '
                                . '"CONFIRMADO, PROGRAMA" or "2025-01-01, 2025-12-31".',
                            required: false,
                        ),
                    ],
                ),
            ),
            new ToolProperty(
                name: 'distinct_column',
                type: PropertyType::STRING,
                description: 'Count unique values of this column as well as rows. On "inscripcion" use '
                    . '"peoples_id" to count people instead of registrations.',
                required: false,
            ),
            new ToolProperty(
                name: 'group_by',
                type: PropertyType::STRING,
                description: 'Return one row per group instead of raw rows — use for charts. Comma-'
                    . 'separated for several columns ("empresa_id, empresa"). A date column takes a '
                    . 'period suffix: "fecha_inicio:month" (also :day, :quarter, :year); it comes back '
                    . 'as "fecha_inicio_month" and is sorted oldest first.',
                required: false,
            ),
            new ArrayProperty(
                name: 'aggregates',
                description: 'Values per group. Omit to get "total" (row count) and, with '
                    . 'distinct_column, "distinct_total".',
                required: false,
                items: new ObjectProperty(
                    name: 'aggregate',
                    description: 'e.g. {"function":"SUM","column":"precio","alias":"ingreso"}.',
                    properties: [
                        new ToolProperty(
                            name: 'function',
                            type: PropertyType::STRING,
                            description: 'COUNT needs no column; the rest do.',
                            required: true,
                            enum: AggregateRequest::FUNCTIONS,
                        ),
                        new ToolProperty(
                            name: 'column',
                            type: PropertyType::STRING,
                            description: 'Column to aggregate.',
                            required: false,
                        ),
                        new ToolProperty(
                            name: 'alias',
                            type: PropertyType::STRING,
                            description: 'Key for this value in each row: lowercase letters, digits, underscores.',
                            required: false,
                        ),
                    ],
                ),
            ),
            new ToolProperty(
                name: 'order_by',
                type: PropertyType::STRING,
                description: 'Sort by an aggregate alias, a group_by entry, or a column. Groups default to '
                    . 'the largest "total" first; rows to their id.',
                required: false,
            ),
            new ToolProperty(
                name: 'descending',
                type: PropertyType::BOOLEAN,
                description: 'Sort direction for order_by. Defaults to true for groups, false for rows.',
                required: false,
            ),
            new ToolProperty(
                name: 'select',
                type: PropertyType::STRING,
                description: 'Rows only: comma-separated columns to return. Omit for every column.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max rows or groups to return. Defaults to 50 rows / 200 groups, hard cap 1000.',
                required: false,
            ),
        ];
    }

    /**
     * @param array<int, mixed>|null $filters
     * @param array<int, mixed>|null $aggregates
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        string $model,
        ?array $filters = null,
        ?string $distinct_column = null,
        ?string $group_by = null,
        ?array $aggregates = null,
        ?string $order_by = null,
        ?bool $descending = null,
        ?string $select = null,
        ?int $limit = null
    ): array {
        try {
            $definition = new ReportRegistry()->find($this->app, $model);
            $service = new ReportQueryService();
            $parsed = array_map(
                fn ($f) => $f instanceof ReportFilter ? $f : ReportFilter::fromArray((array) $f),
                $filters ?? []
            );
            $distinct = Str::trimToNull($distinct_column);
            $groupBy = Str::commaList($group_by);
            $counts = $service->count(
                $definition,
                $this->app,
                $this->company,
                $parsed,
                $distinct
            );

            if ($groupBy !== [] || ($aggregates ?? []) !== []) {
                $request = $this->aggregateRequest(
                    $groupBy,
                    $aggregates ?? [],
                    $distinct,
                    Str::trimToNull($order_by),
                    $descending ?? true,
                    $limit ?? self::DEFAULT_GROUP_LIMIT
                );
                $breakdown = $service->aggregate($definition, $this->app, $this->company, $request, $parsed);

                return [
                    'model' => $model,
                    'grain' => $definition->grain()->description(),
                    'group_by' => array_map(ReportQueryService::groupingKey(...), $groupBy),
                    'total_rows' => $counts['rows'],
                    'total_distinct' => $counts['distinct'],
                    'truncated' => count($breakdown) >= $request->limit,
                    'breakdown' => $breakdown,
                ];
            }

            $rows = $service->search(
                $definition,
                $this->app,
                $this->company,
                $parsed,
                select: Str::commaList($select) ?: null,
                limit: $limit ?? 50,
                orderBy: Str::trimToNull($order_by),
                descending: $descending ?? false,
            );

            return [
                'model' => $model,
                'grain' => $definition->grain()->description(),
                'total_rows' => $counts['rows'],
                'total_distinct' => $counts['distinct'],
                'returned' => count($rows),
                'truncated' => $counts['rows'] > count($rows),
                'rows' => $rows,
            ];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @param list<string> $groupBy
     * @param array<int, mixed> $aggregates
     */
    private function aggregateRequest(
        array $groupBy,
        array $aggregates,
        ?string $distinctColumn,
        ?string $orderBy,
        bool $descending,
        int $limit
    ): AggregateRequest {
        $aggregates = array_map(fn ($aggregate) => (array) $aggregate, $aggregates);
        $defaulted = $aggregates === [];

        if ($defaulted) {
            $aggregates[] = ['function' => 'COUNT', 'alias' => 'total'];

            if ($distinctColumn !== null) {
                $aggregates[] = ['function' => 'COUNT_DISTINCT', 'column' => $distinctColumn, 'alias' => 'distinct_total'];
            }
        }

        // A date period reads left to right in time, which the service does when nothing else is
        // asked for. Anything else sorts the biggest group first.
        $hasPeriod = array_filter($groupBy, fn (string $column) => str_contains($column, ':')) !== [];

        return AggregateRequest::fromInput(
            aggregates: $aggregates,
            groupBy: $groupBy,
            orderBy: $orderBy ?? ($defaulted && ! $hasPeriod ? 'total' : null),
            descending: $descending,
            limit: $limit,
        );
    }
}
