<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CompanyConfiguration;

use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\RunsInExplicitCompany;
use Kanvas\Intelligence\Agents\Neuron\Tools\Workflow\ListWorkflowOptionsTool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * list_workflow_options for the Company Configuration Administrator: the catalog as an explicitly
 * authorized company sees it (its receivers and connected integrations).
 */
class AppScopedListWorkflowOptionsTool extends ListWorkflowOptionsTool
{
    use GuardsAdminForTool;
    use RunsInExplicitCompany;

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [...parent::properties(), $this->companyUuidProperty()];
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function __invoke(?string $kind = null, ?string $search = null, ?string $company_uuid = null): array
    {
        if ($company_uuid !== null) {
            return $this->inExplicitCompany($company_uuid, $this->contextAgent(), fn (self $tool): array => $tool($kind, $search));
        }

        return parent::__invoke($kind, $search);
    }
}
