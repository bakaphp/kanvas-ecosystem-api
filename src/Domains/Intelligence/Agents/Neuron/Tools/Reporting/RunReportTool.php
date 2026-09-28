<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Reporting;

use Kanvas\Analytics\Reporting\DataTransferObject\ReportFilter;
use Kanvas\Analytics\Reporting\Services\ReportQueryService;
use Kanvas\Analytics\Reporting\Support\ReportRegistry;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
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
 */
#[AgentTool(name: 'Run Report', category: 'reporting')]
class RunReportTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'run_report',
            description: 'Query a reporting model with filters and get back rows plus counts. '
                . 'Call describe_report_model first to get the column names. Filters are ANDed. '
                . 'On a person-per-event model, set distinct_column to count people rather than '
                . 'registrations. Set group_by to get a breakdown for a chart instead of rows.',
        );
    }

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
            new ToolProperty(
                name: 'filters',
                type: PropertyType::ARRAY,
                description: 'Conditions, ANDed. Each is {"column":"nivel","operator":"=","value":"Gerencial"}. '
                    . 'Operators: =, !=, >, >=, <, <=, LIKE, IN, NOT IN, BETWEEN, IS NULL, IS NOT NULL, '
                    . 'MEMBER OF (arrays only — check multi_valued in describe_report_model).',
                required: false,
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
                description: 'Return counts grouped by this column instead of rows — use for charts.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max rows to return. Defaults to 50, hard cap 1000.',
                required: false,
            ),
        ];
    }

    /**
     * @param array<int, mixed>|null $filters
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        string $model,
        ?array $filters = null,
        ?string $distinct_column = null,
        ?string $group_by = null,
        ?int $limit = null
    ): array {
        try {
            $definition = new ReportRegistry()->find($this->app, $model);
            $service = new ReportQueryService();

            $parsed = array_map(
                fn ($f) => $f instanceof ReportFilter ? $f : ReportFilter::fromArray((array) $f),
                $filters ?? []
            );

            $counts = $service->count($definition, $this->app, $this->company, $parsed, $distinct_column);

            if ($group_by !== null) {
                return [
                    'model' => $model,
                    'grain' => $definition->grain()->description(),
                    'group_by' => $group_by,
                    'total_rows' => $counts['rows'],
                    'total_distinct' => $counts['distinct'],
                    'breakdown' => $service->breakdown(
                        $definition,
                        $this->app,
                        $this->company,
                        $group_by,
                        $parsed,
                        $distinct_column
                    ),
                ];
            }

            $rows = $service->search(
                $definition,
                $this->app,
                $this->company,
                $parsed,
                limit: $limit ?? 50
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
}
