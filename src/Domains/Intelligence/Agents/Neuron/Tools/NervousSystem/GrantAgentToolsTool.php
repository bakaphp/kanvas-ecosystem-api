<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\NervousSystem\Capability\Enums\AgentAbilityEnum;
use Kanvas\NervousSystem\Capability\Models\Tool as CapabilityTool;
use Kanvas\NervousSystem\Capability\Services\ToolGrantResolver;
use Kanvas\NervousSystem\Project\Models\ProjectMember;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Give an existing teammate a tool it does not have yet.
 *
 * The counterpart to hiring: hiring refuses a duplicate name, so an agent hired under-equipped could
 * otherwise never be corrected. It also covers the commoner case — the right agent exists and is
 * simply short one tool.
 *
 * `update_agent_instructions` changes only what an agent is TOLD; this changes what it can TOUCH, so
 * it carries the guards for widening a grant: an admin authorises it, the target must be on the
 * caller's team, and no agent may equip ITSELF.
 */
#[AgentTool(name: 'Grant Agent Tools', category: 'nervous_system')]
class GrantAgentToolsTool extends Tool
{
    use GuardsAdminForTool;
    use HasKanvasContext;

    public function __construct(
        private readonly ?Agent $granter = null,
    ) {
        parent::__construct(
            name: 'grant_agent_tools',
            description: 'Give one of your teammate agents additional tools from the catalog, so it can do '
                . 'work it is currently missing a capability for. Admin only. Use it when an agent is the '
                . 'right one for a job but lacks a tool — that is a grant, not a reason to hire a second '
                . 'agent or to report a capability gap. Look the names up with capability_lookup first. '
                . 'Tools already held are left alone, and nothing is ever removed. You cannot grant tools '
                . 'to yourself.',
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
                name: 'agent_id',
                type: PropertyType::INTEGER,
                description: 'The teammate to equip. It must be one you hired or share a project with.',
                required: true,
            ),
            new ToolProperty(
                name: 'tools',
                type: PropertyType::STRING,
                description: 'Comma-separated catalog tool names, e.g. "Create Lead, Send Email". Use the '
                    . 'exact names capability_lookup reports.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $agent_id, string $tools): array
    {
        if ($denied = $this->requireRequestingAdminOrError()) {
            return $this->error((string) $denied['message']);
        }

        if (! $this->hasTenantContext()) {
            return $this->error('This tool has no company context, so it cannot equip an agent.');
        }

        if ($this->granter === null) {
            return $this->error('This tool does not know which agent is calling it, so it cannot check '
                . 'whether the target is on your team.');
        }

        if ($agent_id === $this->granter->getId()) {
            return $this->error('You cannot grant tools to yourself. Ask a person to widen your own toolset.');
        }

        $target = Agent::query()
            ->whereKey($agent_id)
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', 0)
            ->first();

        if ($target === null) {
            return $this->error('Agent ' . $agent_id . ' is not in this company.');
        }

        if (! $this->mayEquip($this->granter, $target)) {
            return $this->error(
                'Agent ' . $target->name . ' is not on any project you are on, so you cannot change what '
                . 'it can do. Add it to the project first, or hire an agent for this work.'
            );
        }

        $resolver = new ToolGrantResolver($this->app);
        $grants = $resolver->resolve($tools, $target->type?->provider);

        if ($grants['refused'] !== []) {
            return $this->error(
                'Nothing was granted. These tools could not be: ' . $resolver->describeRefusals($grants['refused'])
                    . ' Call grant_agent_tools again with the rest, or fix the names.',
                ['refused' => $grants['refused']],
            );
        }

        if ($grants['tools'] === []) {
            return $this->error('Name at least one tool to grant.');
        }

        try {
            $changes = $target->selectedTools()->syncWithoutDetaching(
                array_map(fn (CapabilityTool $tool): int => (int) $tool->getKey(), $grants['tools']),
            );
        } catch (Throwable $e) {
            report($e);

            return $this->error('Could not save the grant: ' . $e->getMessage());
        }

        $addedIds = array_map(intval(...), $changes['attached'] ?? []);
        $added = array_values(array_filter(
            $grants['tools'],
            fn (CapabilityTool $tool): bool => in_array((int) $tool->getKey(), $addedIds, true),
        ));

        return [
            'status' => 'success',
            'agent_id' => $target->getId(),
            'agent' => $target->name,
            'granted' => array_map(fn (CapabilityTool $tool): string => $tool->name, $added),
            'already_held' => array_values(array_diff(
                array_map(fn (CapabilityTool $tool): string => $tool->name, $grants['tools']),
                array_map(fn (CapabilityTool $tool): string => $tool->name, $added),
            )),
            'note' => 'It takes effect the next time that agent runs. Assign it the work now — it does '
                . 'nothing until something reaches it.',
        ];
    }

    /**
     * The same team boundary `update_agent_instructions` uses: agents you hired, or agents you work
     * alongside. Hires are included because a fresh one belongs to no project yet, so a project-only
     * rule would leave the agent that created it unable to finish equipping it.
     */
    private function mayEquip(Agent $granter, Agent $target): bool
    {
        if ((int) $target->parent_id === $granter->getId()) {
            return true;
        }

        $granterProjects = ProjectMember::query()
            ->where('agent_id', $granter->getId())
            ->where('is_deleted', 0)
            ->pluck('project_id');

        if ($granterProjects->isEmpty()) {
            return false;
        }

        return ProjectMember::query()
            ->where('agent_id', $target->getId())
            ->whereIn('project_id', $granterProjects)
            ->where('is_deleted', 0)
            ->exists();
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function error(string $message, array $extra = []): array
    {
        return array_merge(['status' => 'error', 'granted' => false, 'message' => $message], $extra);
    }

    /**
     * @return list<string>
     */
    protected function requiredAbilities(): array
    {
        return [AgentAbilityEnum::HIRE_AGENT->value];
    }
}
