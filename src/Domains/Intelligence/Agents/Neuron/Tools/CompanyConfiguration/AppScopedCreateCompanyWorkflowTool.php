<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CompanyConfiguration;

use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\RunsInExplicitCompany;
use Kanvas\Intelligence\Agents\Neuron\Tools\Workflow\CreateCompanyWorkflowTool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * create_company_workflow for the Company Configuration Administrator: the stable creation, written
 * into an explicitly authorized company. Still never global: the executor resolves one concrete
 * company of the app or refuses.
 */
class AppScopedCreateCompanyWorkflowTool extends CreateCompanyWorkflowTool
{
    use RunsInExplicitCompany;

    protected ?string $description = 'Creates an automation workflow for a company: when <trigger> happens on <entity>, '
        . 'run <actions> — optionally only when conditions match. Admin only: the person you are talking to '
        . 'must be an administrator. The workflow belongs to the current company, or to the company named by '
        . 'company_uuid when you are authorized for it; you cannot create a global/platform-wide workflow. '
        . 'Call list_workflow_options first to get the valid trigger, entity and action names — never invent them.';

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
        string $name,
        string $entity,
        string $trigger,
        string $actions,
        ?string $params = null,
        ?string $conditions = null,
        ?string $description = null,
        ?bool $run_in_background = null,
        ?string $company_uuid = null,
    ): array {
        if ($company_uuid !== null) {
            return $this->inExplicitCompany($company_uuid, $this->contextAgent(), fn (self $tool): array => $tool(
                $name,
                $entity,
                $trigger,
                $actions,
                $params,
                $conditions,
                $description,
                $run_in_background,
            ));
        }

        return parent::__invoke(
            $name,
            $entity,
            $trigger,
            $actions,
            $params,
            $conditions,
            $description,
            $run_in_background,
        );
    }
}
