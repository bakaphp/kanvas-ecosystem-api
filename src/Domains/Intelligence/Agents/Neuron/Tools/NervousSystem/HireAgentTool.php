<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Actions\HireAgentAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\NervousSystem\Capability\Enums\AgentAbilityEnum;
use Kanvas\NervousSystem\Capability\Models\Tool as CapabilityTool;
use Kanvas\NervousSystem\Capability\Services\ActiveIntegrationsService;
use Kanvas\NervousSystem\Capability\Services\AgentTypeResolver;
use Kanvas\NervousSystem\Capability\Services\ToolGrantResolver;
use Kanvas\NervousSystem\Plan\Support\MentionHandle;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Bring a teammate agent into existence: its own user, its own instructions, its own tools.
 *
 * **The hire is equipped from the catalog, not from the hirer's own toolset** — see
 * `ToolGrantResolver` for what bounds that instead.
 *
 * Three limits, each structural rather than advisory:
 *  - **A human authorises it.** Hiring mints a real user account and ongoing model spend, so it needs
 *    an identified admin in the conversation, not the agent's own (usually admin) user.
 *  - **Nothing that re-equips agents is delegable.** Hiring and re-toolings stay with a human, which
 *    is what keeps fan-out bounded.
 *  - **A headcount cap**, because hiring is unbounded fan-out by construction.
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
                . 'not exist yet — a writer, a researcher, someone to run an import. Admin only. This is how '
                . 'you get work done that YOUR OWN tools cannot do: you can grant the hire ANY tool in the '
                . 'catalog, including ones you do not hold yourself, so a capability you lack is a reason to '
                . 'hire rather than a reason to stop. Look the tools up with capability_lookup first and pass '
                . 'their exact names, and pick the KIND of agent with agent_type — list_agent_types shows what '
                . 'exists, including coding agents that work in a sandbox and open pull requests. You must give '
                . 'it complete instructions describing its job. It gets its '
                . 'own identity, so its work is attributed to it and not to you, and you can retune it later. '
                . 'Check first whether an existing agent already does this job — assign the work to that one '
                . 'instead of hiring a duplicate.',
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
                description: 'Comma-separated catalog tool names to grant it, e.g. "Create Lead, Send Email". '
                    . 'Any tool in the catalog is allowed, whether or not you hold it — use capability_lookup '
                    . 'to get the exact names. Grant everything the job needs and nothing more; an agent '
                    . 'hired without the tools for its job can do nothing at all.',
                required: false,
            ),
            new ToolProperty(
                name: 'agent_type',
                type: PropertyType::STRING,
                description: 'Which kind of agent to build this on — call list_agent_types for the exact '
                    . 'names and what each one is for. This decides what the hire can physically do: a '
                    . 'conversational type reads and writes records, a coding type works in a sandbox and '
                    . 'opens pull requests. Pick by the job. Some types need credentials set by a human '
                    . 'after hiring — the result says which, and you must pass that on. Omit only for '
                    . 'ordinary conversational work.',
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
        ?string $agent_type = null,
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
            return $this->error('This tool does not know which agent is calling it, so the hire could not '
                . 'be linked back to a hirer.');
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

        $types = new AgentTypeResolver($this->app);
        $requestedType = trim((string) $agent_type);
        $agentType = $requestedType !== ''
            ? $types->resolve($requestedType)
            : $types->default();

        if ($agentType === null) {
            return $this->error(
                $requestedType !== ''
                    ? sprintf(
                        'There is no agent type called "%s". Call list_agent_types and use one of: %s.',
                        $requestedType,
                        implode(', ', $types->names())
                    )
                    : 'No agent type is available to build on. Tell the admin.',
            );
        }

        $unavailable = $types->unavailableFor(new ActiveIntegrationsService($this->company)->names());

        if (in_array($agentType->name, $unavailable, true)) {
            return $this->error(sprintf(
                'Nothing was hired. "%s" runs on an integration this company has not connected, so the '
                . 'hire would exist and never be able to run. Ask an admin to connect it, or hire a '
                . 'different type — call list_agent_types to see which ones are available here.',
                $agentType->name
            ));
        }

        // Resolved against the runtime the hire will actually run on, so a tool that exists only for
        // another framework is refused here rather than granted and silently filtered out at startup.
        $resolver = new ToolGrantResolver($this->app);
        $grants = $resolver->resolve($tools, $agentType->provider);

        if ($grants['refused'] !== []) {
            return $this->error(
                'Nothing was hired. These tools could not be granted: ' . $resolver->describeRefusals($grants['refused'])
                    . ' Call hire_agent again with the rest, or fix the names.',
                ['refused' => $grants['refused']],
            );
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

        $requires = $types->requirementsOf($agentType);

        return [
            'hired' => true,
            'agent_id' => $hired->getId(),
            'name' => $hired->name,
            // The form an @mention must take — its display name reaches nobody.
            'handle' => MentionHandle::forUser($hired->user, $this->app),
            'agent_type' => $agentType->name,
            'tools' => array_map(fn (CapabilityTool $tool): string => $tool->name, $grants['tools']),
            'needs_from_an_admin' => $requires,
            'message' => 'Hired, with its own identity so its work is attributed to it rather than to you. '
                . 'It does nothing until something reaches it — assign it a task, or point a workflow at it '
                . 'with its agent_id.'
                . ($requires === []
                    ? ''
                    : ' IT IS NOT READY YET: this type needs things only a human can set, listed in '
                        . 'needs_from_an_admin. Tell the person who asked, name them exactly, and do not '
                        . 'assign it work that depends on them until an admin confirms they are set.'),
        ];
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
