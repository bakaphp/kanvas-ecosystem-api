<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CompanyConfiguration;

use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\ListAgentTypesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\RunsInExplicitCompany;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * list_agent_types for the Company Configuration Administrator: the same catalog, resolved against
 * the integrations of an explicitly authorized company.
 */
class AppScopedListAgentTypesTool extends ListAgentTypesTool
{
    use GuardsAdminForTool;
    use RunsInExplicitCompany;

    protected ?string $description = 'List the kinds of agent you can hire — a plain conversational teammate, a coding '
        . 'agent that works in a sandbox and opens pull requests, a long-running one for multi-hour '
        . 'work, and the domain agents. Call this BEFORE hire_agent whenever the job is anything '
        . 'beyond reading and writing records, then pass the name you picked as hire_agent\'s '
        . 'agent_type. It also tells you what each type still needs from a human after hiring — a '
        . 'coding agent is not usable until an admin gives it a GitHub token and the repositories '
        . 'it may touch. Do not answer that the platform cannot do something technical without '
        . 'checking this first. Pass company_uuid to read the catalog as another company sees it; '
        . 'omit it for the current company.';

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
    public function __invoke(?string $company_uuid = null): array
    {
        if ($company_uuid !== null) {
            return $this->inExplicitCompany($company_uuid, $this->contextAgent(), fn (self $tool): array => $tool());
        }

        return parent::__invoke();
    }
}
