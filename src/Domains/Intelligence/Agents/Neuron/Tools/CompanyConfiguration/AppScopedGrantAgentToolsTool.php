<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CompanyConfiguration;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\GrantAgentToolsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\RunsInExplicitCompany;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * grant_agent_tools for the Company Configuration Administrator. In an explicitly authorized
 * company the app administrator may equip any agent of that company; see
 * AppScopedUpdateAgentInstructionsTool for why the project boundary does not apply there.
 */
class AppScopedGrantAgentToolsTool extends GrantAgentToolsTool
{
    use RunsInExplicitCompany;

    public function __construct(private readonly ?Agent $granter = null)
    {
        parent::__construct($granter);
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
    public function __invoke(int $agent_id, string $tools, ?string $company_uuid = null): array
    {
        if ($company_uuid !== null) {
            return $this->inExplicitCompany($company_uuid, $this->granter, fn (self $tool): array => $tool($agent_id, $tools));
        }

        return parent::__invoke($agent_id, $tools);
    }

    #[Override]
    protected function mayEquip(Agent $granter, Agent $target): bool
    {
        return $this->explicitCompanyAuthorized || parent::mayEquip($granter, $target);
    }
}
