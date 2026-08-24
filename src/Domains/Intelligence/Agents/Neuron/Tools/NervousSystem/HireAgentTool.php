<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Actions\HireAgentAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\NervousSystem\Capability\Enums\AgentAbilityEnum;
use Kanvas\NervousSystem\Capability\Models\Tool as CapabilityTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Bring a teammate agent into existence: its own user, its own instructions, its own tools.
 *
 * Three limits, each structural rather than advisory:
 *  - **No privilege escalation.** The hire can only be granted tools the hiring agent already holds.
 *    Without that, an agent denied a capability could create a child that has it and call the child —
 *    laundering a permission nobody approved.
 *  - **A human authorises it.** Hiring mints a real user account and ongoing model spend, so it needs
 *    an identified admin in the conversation, not the agent's own (usually admin) user.
 *  - **A headcount cap**, because hiring is unbounded fan-out by construction: an agent that can hire
 *    can hire something that hires.
 */
#[AgentTool(name: 'Hire Agent', category: 'nervous_system')]
class HireAgentTool extends Tool
{
    use GuardsAdminForTool;
    use HasKanvasContext;

    private const int MAX_AGENTS_PER_COMPANY = 50;

    public function __construct(
        private readonly ?Agent $hiringAgent = null,
    ) {
        parent::__construct(
            name: 'hire_agent',
            description: 'Create a new teammate agent for this company when the work needs someone who does '
                . 'not exist yet — a writer, a researcher, a reviewer. Admin only. You must give it complete '
                . 'instructions describing its job, and you can only pass on tools YOU already hold — if you '
                . 'name one you do not have, the call is refused and your own tools are listed back to you. '
                . 'It gets its own identity, so its work is attributed to it and not to you, and you can '
                . 'retune its instructions later. Check first whether an existing agent already does this '
                . 'job — retune that one instead of hiring a duplicate.',
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
                name: 'name',
                type: PropertyType::STRING,
                description: 'Short name for the agent, e.g. "Newsroom". Must be unique in this company.',
                required: true,
            ),
            new ToolProperty(
                name: 'role',
                type: PropertyType::STRING,
                description: 'What it is, in a few words, e.g. "Newsroom writer".',
                required: true,
            ),
            new ToolProperty(
                name: 'instructions',
                type: PropertyType::STRING,
                description: 'The COMPLETE instructions for its job: what it reads, what it decides, what it '
                    . 'produces, and — importantly — when it should do NOTHING. An agent never told that '
                    . 'inaction is acceptable will find something to do every time it runs.',
                required: true,
            ),
            new ToolProperty(
                name: 'tools',
                type: PropertyType::STRING,
                description: 'Comma-separated tool names to grant it, e.g. "Read Channel Window, Create '
                    . 'Message". Only tools you hold yourself are allowed; anything else is refused and '
                    . 'listed back to you. Grant the minimum the job needs.',
                required: false,
            ),
            new ToolProperty(
                name: 'soul',
                type: PropertyType::STRING,
                description: 'Optional persona — who it is and how it carries itself.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $name,
        string $role,
        string $instructions,
        ?string $tools = null,
        ?string $soul = null,
    ): array {
        // The shared guard answers in create/update shape; this tool's callers read `hired`, and a
        // missing key reads as "no answer" rather than "refused".
        if ($denied = $this->requireRequestingAdminOrError()) {
            return $this->error((string) $denied['message']);
        }

        if (! $this->hasTenantContext()) {
            return $this->error('This agent has no company context, so it cannot hire.');
        }

        if ($this->hiringAgent === null) {
            return $this->error('This tool does not know which agent is calling it, so it cannot check '
                . 'which tools may be passed on.');
        }

        $headcount = Agent::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', 0)
            ->count();

        if ($headcount >= self::MAX_AGENTS_PER_COMPANY) {
            return $this->error(sprintf(
                'This company already has %d agents and the limit is %d. Retune or retire one before '
                . 'hiring another.',
                $headcount,
                self::MAX_AGENTS_PER_COMPANY
            ));
        }

        $grants = $this->resolveGrants($tools);

        if (isset($grants['error'])) {
            return $this->error($grants['error'], ['your_tools' => $this->ownToolNames()]);
        }

        $agentType = $this->genericAgentType();

        if ($agentType === null) {
            return $this->error('No generic agent type is available to build on. Tell the admin.');
        }

        try {
            $hired = new HireAgentAction(
                app: $this->app,
                company: $this->company,
                hiredBy: $this->requestingUser ?? $this->user,
                hiredByAgent: $this->hiringAgent,
                agentType: $agentType,
                name: $name,
                role: $role,
                instructions: $instructions,
                tools: $grants['tools'],
                soul: $soul !== null && trim($soul) !== '' ? trim($soul) : null,
            )->execute();
        } catch (Throwable $e) {
            return $this->error($e->getMessage());
        }

        return [
            'hired' => true,
            'agent_id' => $hired->getId(),
            'name' => $hired->name,
            'tools' => array_map(fn (CapabilityTool $tool): string => $tool->name, $grants['tools']),
            'message' => 'Hired, with its own identity so its work is attributed to it rather than to you. '
                . 'It does nothing until something reaches it — assign it a task, or point a workflow at it '
                . 'with its agent_id.',
        ];
    }

    /**
     * The escalation guard: a hire can hold no tool its hirer does not already hold.
     *
     * @return array{tools: list<CapabilityTool>, error?: string}
     */
    private function resolveGrants(?string $tools): array
    {
        $requested = array_values(array_filter(array_map('trim', explode(',', (string) $tools))));

        if ($requested === []) {
            return ['tools' => []];
        }

        $own = $this->ownTools();
        $ownByName = [];

        foreach ($own as $tool) {
            $ownByName[mb_strtolower($tool->name)] = $tool;
        }

        $granted = [];
        $refused = [];

        foreach ($requested as $name) {
            $key = mb_strtolower($name);

            if (isset($ownByName[$key])) {
                $granted[] = $ownByName[$key];

                continue;
            }

            $refused[] = $name;
        }

        if ($refused !== []) {
            return [
                'tools' => [],
                'error' => sprintf(
                    'You cannot pass on %s — you do not hold %s yourself. An agent can only grant what it '
                    . 'already has; ask an admin to grant it to you first.',
                    sprintf('"%s"', implode('", "', $refused)),
                    count($refused) === 1 ? 'it' : 'them'
                ),
            ];
        }

        return ['tools' => $granted];
    }

    /**
     * @return list<CapabilityTool>
     */
    private function ownTools(): array
    {
        return $this->hiringAgent->selectedTools()->get()->all();
    }

    /**
     * @return list<string>
     */
    private function ownToolNames(): array
    {
        return array_map(fn (CapabilityTool $tool): string => $tool->name, $this->ownTools());
    }

    /**
     * Hires are built on the generic handler so their behaviour is their instructions — a coded agent
     * type would need a deploy per job.
     */
    private function genericAgentType(): ?AgentType
    {
        return AgentType::query()
            ->where('name', 'Generic Neuron Agent')
            ->first()
            ?? $this->hiringAgent->type;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function error(string $message, array $extra = []): array
    {
        return array_merge(['hired' => false, 'message' => $message], $extra);
    }

    /**
     * @return list<string>
     */
    protected function requiredAbilities(): array
    {
        return [AgentAbilityEnum::HIRE_AGENT->value];
    }
}
