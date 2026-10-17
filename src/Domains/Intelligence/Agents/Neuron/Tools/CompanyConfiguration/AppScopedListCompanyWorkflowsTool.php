<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CompanyConfiguration;

use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\RunsInExplicitCompany;
use Kanvas\Intelligence\Agents\Neuron\Tools\Workflow\ListCompanyWorkflowsTool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * list_company_workflows for the Company Configuration Administrator: the workflows of an
 * explicitly authorized company.
 */
class AppScopedListCompanyWorkflowsTool extends ListCompanyWorkflowsTool
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
    public function __invoke(?string $search = null, ?string $company_uuid = null): array
    {
        if ($company_uuid !== null) {
            return $this->inExplicitCompany($company_uuid, $this->contextAgent(), fn (self $tool): array => $tool($search));
        }

        return parent::__invoke($search);
    }
}
