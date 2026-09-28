<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Apollo\Actions\CleanupReportAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * The KPI numbers behind the Command Center home view: how healthy the CRM people base is.
 * Scoped to the agent's current company.
 */
#[AgentTool(name: 'Get Cleanup Report', category: 'crm')]
class GetCleanupReportTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'get_cleanup_report',
            description: 'Health metrics for the CRM people base: total people, how many are verified, and how '
                . 'many changed company / title / email / were promoted / are bouncing. Use for any "how many…", '
                . '"what percentage…", "how healthy is my data" question. Optionally bounded to a date range '
                . '(ISO dates, e.g. 2026-06-01); omit for all time.',
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
                name: 'from',
                type: PropertyType::STRING,
                description: 'Optional range start as an ISO date (YYYY-MM-DD). Resolve natural phrases like "este mes" to a date before calling.',
                required: false,
            ),
            new ToolProperty(
                name: 'to',
                type: PropertyType::STRING,
                description: 'Optional range end as an ISO date (YYYY-MM-DD).',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $from = null, ?string $to = null): array
    {
        $report = new CleanupReportAction(
            app: $this->app,
            company: $this->company,
            from: ! empty($from) ? Carbon::parse($from)->startOfDay() : null,
            to: ! empty($to) ? Carbon::parse($to)->endOfDay() : null,
        )->execute();

        unset($report['byCompany']);
        $report['company'] = $this->company->name;

        return $report;
    }
}
