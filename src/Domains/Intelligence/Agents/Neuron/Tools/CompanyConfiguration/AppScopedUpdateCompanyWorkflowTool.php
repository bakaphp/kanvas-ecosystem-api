<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CompanyConfiguration;

use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\RunsInExplicitCompany;
use Kanvas\Intelligence\Agents\Neuron\Tools\Workflow\UpdateCompanyWorkflowTool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * update_company_workflow for the Company Configuration Administrator: the stable update, applied
 * to a workflow of an explicitly authorized company.
 */
class AppScopedUpdateCompanyWorkflowTool extends UpdateCompanyWorkflowTool
{
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
    public function __invoke(
        int $workflow_id,
        ?string $conditions = null,
        ?string $params = null,
        ?string $actions = null,
        ?string $name = null,
        ?bool $is_active = null,
        ?string $company_uuid = null,
    ): array {
        if ($company_uuid !== null) {
            return $this->inExplicitCompany($company_uuid, $this->contextAgent(), fn (self $tool): array => $tool(
                $workflow_id,
                $conditions,
                $params,
                $actions,
                $name,
                $is_active,
            ));
        }

        return parent::__invoke(
            $workflow_id,
            $conditions,
            $params,
            $actions,
            $name,
            $is_active,
        );
    }
}
