<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Neuron\Tools;

use Kanvas\Connectors\Movipass\Repositories\RoadsideAssistanceMetricsRepository;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Roadside Assistance Metrics', category: 'commerce')]
class RoadsideAssistanceMetricsTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'movipass_roadside_metrics',
            description: 'Service-level metrics for roadside-assistance cases: total cases, resolved, completed '
                . 'without resolution, cancelled, plus the average response time (case opened to mechanic on site) '
                . 'and average resolution time (case opened to service completed). Use for "how fast do we respond", '
                . '"how many assistance cases last month", "what is our resolution rate", SLA reporting. Defaults '
                . 'to the last 30 days when no dates are given. Times are reported in both seconds and hours; a '
                . 'null average means no case in the range reached that stage.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'since', type: PropertyType::STRING, description: 'Lower-bound case date, ISO YYYY-MM-DD. Defaults to 30 days ago.', required: false),
            new ToolProperty(name: 'until', type: PropertyType::STRING, description: 'Upper-bound case date, ISO YYYY-MM-DD. Defaults to today.', required: false),
            new ToolProperty(name: 'provider_company_id', type: PropertyType::INTEGER, description: 'Optional provider (tow/mechanic) company id to restrict the cases to. Omit for every provider.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $since = null,
        ?string $until = null,
        ?int $provider_company_id = null,
    ): array {
        return RoadsideAssistanceMetricsRepository::getMetrics(
            app: $this->app,
            startDate: $since,
            endDate: $until,
            providerCompanyId: $provider_company_id,
            companyId: $this->company->getId(),
        );
    }
}
