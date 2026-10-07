<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CompanyConfiguration;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\HireAgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\RunsInExplicitCompany;
use Kanvas\NervousSystem\Capability\Models\Tool as CapabilityTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * hire_agent for the Company Configuration Administrator: the stable hire, in an explicitly
 * authorized company, plus the sub-agent shape a copy between companies needs (a child with its own
 * local callable tool under a parent of the destination company). The base tool keeps hiring
 * independent teammates only.
 */
class AppScopedHireAgentTool extends HireAgentTool
{
    use RunsInExplicitCompany;

    private bool $subAgent = false;

    private ?Agent $parent = null;

    public function __construct(private readonly ?Agent $hirer = null)
    {
        parent::__construct($hirer);
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            ...parent::properties(),
            new ToolProperty(
                name: 'is_sub_agent',
                type: PropertyType::BOOLEAN,
                description: 'Optional, defaults to false. True creates a sub-agent whose parent is the hiring '
                    . 'agent (or parent_agent_id), with its own local callable tool. Does not automatically grant that tool to anyone.',
                required: false,
            ),
            new ToolProperty(
                name: 'parent_agent_id',
                type: PropertyType::INTEGER,
                description: 'For a sub-agent: parent agent ID in the destination company. Required when '
                    . 'the hiring agent is global. Omit to use the hiring agent as parent.',
                required: false,
            ),
            $this->companyUuidProperty(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function __invoke(
        string $name,
        string $role,
        string $instructions,
        ?string $tools = null,
        ?string $agent_type = null,
        ?string $soul = null,
        ?bool $is_sub_agent = null,
        ?int $parent_agent_id = null,
        ?string $company_uuid = null,
    ): array {
        if ($company_uuid !== null) {
            return $this->inExplicitCompany($company_uuid, $this->hirer, fn (self $tool): array => $tool(
                $name,
                $role,
                $instructions,
                $tools,
                $agent_type,
                $soul,
                $is_sub_agent,
                $parent_agent_id,
            ));
        }

        $this->subAgent = $is_sub_agent ?? false;
        $this->parent = null;

        if ($parent_agent_id !== null) {
            if (! $this->subAgent) {
                return $this->error('parent_agent_id is only valid with is_sub_agent=true.');
            }
            if (! $this->hasTenantContext()) {
                return $this->error('This agent has no company context, so it cannot hire.');
            }
            $this->parent = Agent::query()->fromApp($this->app)->fromCompany($this->company)->notDeleted()
                ->whereKey($parent_agent_id)->first();
            if ($this->parent === null) {
                return $this->error('The parent agent must belong to the destination app and company.');
            }
        }

        $result = parent::__invoke(
            $name,
            $role,
            $instructions,
            $tools,
            $agent_type,
            $soul,
        );
        if (! ($result['hired'] ?? false)) {
            return $result;
        }

        $hired = Agent::query()->whereKey($result['agent_id'])->first();
        $callable = $hired?->is_sub_agent
            ? CapabilityTool::query()->fromApp($this->app)->where('agents_id', $hired->getId())->first()
            : null;

        return [
            ...$result,
            'is_sub_agent' => (bool) $hired?->is_sub_agent,
            'parent_id' => $hired?->parent_id,
            'sub_agent_tool_id' => $callable?->getId(),
            'sub_agent_tool_name' => $callable?->name,
            'message' => $hired?->is_sub_agent
                ? 'Created a sub-agent with its own identity and local callable tool. An administrator must '
                    . 'assign that tool to its caller before use.'
                    . (($result['needs_from_an_admin'] ?? []) === []
                        ? ''
                        : ' IT IS NOT READY YET: this type needs things only a human can set, listed in needs_from_an_admin.')
                : $result['message'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function hireOptions(): array
    {
        return ['isSubAgent' => $this->subAgent, 'parentAgent' => $this->parent];
    }
}
