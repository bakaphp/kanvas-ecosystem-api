<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Connectors\Apollo\Actions\CleanupReportAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Ranks the employer accounts inside the CRM by data freshness (up-to-date vs outdated records)
 * and summarizes the current CRM's contribution. Scoped to the agent's current company.
 */
#[AgentTool(name: 'Get Company Breakdown', category: 'crm')]
class GetCompanyBreakdownTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'get_company_breakdown',
            description: 'Ranks the accounts/companies inside the CRM by data freshness (up-to-date vs outdated '
                . 'records). Use for "which company is worst", "where should I clean first", "break it down by '
                . 'company". Returns the top-N accounts by staleness plus a summary for the current CRM.',
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
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Top-N accounts by staleness to return. Defaults to 50, max 200.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $limit = null): array
    {
        $limit = max(1, min(200, $limit ?? 50));

        $report = new CleanupReportAction(
            app: $this->app,
            company: $this->company,
            topCompanies: $limit,
        )->execute();

        return [
            'byCompany' => $report['byCompany'],
            'byTenant' => [
                [
                    'crm' => $this->company->name,
                    'totalPeople' => $report['totalPeople'],
                    'verifiedPct' => $report['verifiedPct'],
                    'bouncingPeople' => $report['bouncingPeople'],
                ],
            ],
        ];
    }
}
