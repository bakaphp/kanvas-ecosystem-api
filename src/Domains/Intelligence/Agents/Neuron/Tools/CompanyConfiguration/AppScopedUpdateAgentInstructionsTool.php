<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CompanyConfiguration;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\UpdateAgentInstructionsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\RunsInExplicitCompany;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * update_agent_instructions for the Company Configuration Administrator. In an explicitly
 * authorized company the app administrator may retune any agent of that company: the project
 * boundary of the base tool exists to stop one agent rewriting its peers, and the executor has
 * already proven a human app administrator is asking.
 */
class AppScopedUpdateAgentInstructionsTool extends UpdateAgentInstructionsTool
{
    use GuardsAdminForTool;
    use RunsInExplicitCompany;

    public function __construct(private readonly ?Agent $editor = null)
    {
        parent::__construct($editor);
    }

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
        int $agent_id,
        string $reason,
        ?string $instructions = null,
        ?string $soul = null,
        ?string $output_format = null,
        ?string $company_uuid = null,
    ): array {
        if ($company_uuid !== null) {
            return $this->inExplicitCompany($company_uuid, $this->editor, fn (self $tool): array => $tool(
                $agent_id,
                $reason,
                $instructions,
                $soul,
                $output_format,
            ));
        }

        return parent::__invoke(
            $agent_id,
            $reason,
            $instructions,
            $soul,
            $output_format,
        );
    }

    #[Override]
    protected function mayRetune(Agent $target): bool
    {
        return $this->explicitCompanyAuthorized || parent::mayRetune($target);
    }
}
