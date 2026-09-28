<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\NervousSystem\Capability\Enums\AgentAbilityEnum;
use Kanvas\NervousSystem\Capability\Models\Tool as CapabilityTool;
use Kanvas\NervousSystem\Plan\Support\MentionHandle;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Models\ProjectMember;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Who is already on staff, before deciding to hire.
 *
 * `list_agent_types` answers what KIND of teammate can exist and `hire_agent` creates one, but
 * nothing answered which teammates already do. An orchestrator could only reach an existing agent by
 * guessing its name at `find_and_add_nervous_system_member`, or through a project it already
 * manages — so the reachable roster was whatever it happened to remember, and the cheapest correct
 * move (assign the agent that already has the tools) was the one it could not see. Every id-taking
 * staffing tool (`grant_agent_tools`, `schedule_agent_task`, `assign_*`) needs an `agent_id` this is
 * now the way to obtain.
 */
#[AgentTool(name: 'List Agents', category: 'nervous_system')]
class ListAgentsTool extends Tool implements HasRunKey
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use TrackByInputs;

    private const int DEFAULT_LIMIT = 25;

    private const int MAX_LIMIT = 50;

    /** A roster is for choosing between teammates, not auditing one — the full grant is a lookup away. */
    private const int MAX_TOOLS_SHOWN = 12;

    private const int MAX_PROJECTS_SHOWN = 5;

    public function __construct(
        private readonly ?Agent $callingAgent = null,
    ) {
        parent::__construct(
            name: 'list_agents',
            description: 'List the teammate agents this company ALREADY has — their agent_id, what each one '
                . 'is for, the tools it holds and whether it can execute board work. Admin only. Call this BEFORE '
                . 'hire_agent: staffing an agent that already exists is wasted headcount and splits the same '
                . 'job across two teammates that then drift. It is also how you get the agent_id that '
                . 'grant_agent_tools, schedule_agent_task and the assign tools need. Filter with capability '
                . 'to find who can already do a thing ("send email", "pull request"), or with search by name. '
                . 'An agent listed with can_execute_board_work false runs elsewhere and cannot own a plan or '
                . 'move a task — do not assign board work to it.',
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
                name: 'capability',
                type: PropertyType::STRING,
                description: 'Keep only agents holding a tool that matches these words, e.g. "email", '
                    . '"invoice", "pull request". This searches what each agent WAS GRANTED, not the whole '
                    . 'catalog — use capability_lookup for that.',
                required: false,
            ),
            new ToolProperty(
                name: 'search',
                type: PropertyType::STRING,
                description: 'Keep only agents whose name or role matches, e.g. "newsroom". Omit to see '
                    . 'everyone.',
                required: false,
            ),
            new ToolProperty(
                name: 'executors_only',
                type: PropertyType::BOOLEAN,
                description: 'True to list only agents that can own a plan and move tasks. Pass it when you '
                    . 'are staffing board work, so a teammate that cannot do it is never offered.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'How many to return, up to ' . self::MAX_LIMIT . ' (default ' . self::DEFAULT_LIMIT . ').',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $capability = null,
        ?string $search = null,
        ?bool $executors_only = null,
        ?int $limit = null,
    ): array {
        // Answered in the roster's own shape, never the guard's — a refusal that came back without
        // `agents` would read as "this company has no agents", which is the one wrong conclusion
        // here: it sends the caller straight to hire_agent to duplicate someone it was not shown.
        if ($denied = $this->requireAdminOrError()) {
            return [
                'count' => 0,
                'agents' => [],
                'error' => $denied['message'],
            ];
        }

        if (! $this->hasTenantContext()) {
            return [
                'count' => 0,
                'agents' => [],
                'error' => 'This agent has no company context, so it cannot read the roster.',
            ];
        }

        $capability = Str::trimToNull($capability);
        $search = Str::trimToNull($search);
        $limit = max(1, min($limit ?? self::DEFAULT_LIMIT, self::MAX_LIMIT));

        $matched = $this->rosterQuery($capability, $search)->get();

        if ($executors_only === true) {
            $matched = $matched->filter(fn (Agent $agent): bool => $agent->canExecuteBoardWork());
        }

        $total = $matched->count();
        $listed = $matched->take($limit);
        $projects = $this->projectsByAgent($listed->pluck('id')->all());

        return [
            'count' => $listed->count(),
            'total' => $total,
            'agents' => $listed
                ->map(fn (Agent $agent): array => $this->present($agent, $projects))
                ->values()
                ->all(),
            'note' => $total === 0
                ? 'No agent matches. Nobody on staff does this, so hire_agent is the right next step — '
                    . 'call list_agent_types first to pick the kind that can physically do the work.'
                : 'Assign to one of these before hiring another. Use agent_id with the staffing tools and '
                    . 'handle to @mention. An agent with no tools was hired and never equipped — '
                    . 'grant_agent_tools fixes that and is cheaper than a second hire.',
        ];
    }

    /**
     * @return Builder<Agent>
     */
    private function rosterQuery(?string $capability, ?string $search): Builder
    {
        $query = Agent::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->with([
                'type',
                'user',
                // canExecuteBoardWork() consults it on every non-hosted agent, so leaving it lazy is
                // a query per row on the answer this tool exists to give.
                'activeDeployment',
                'selectedTools' => fn ($tools) => $tools->active(),
            ])
            ->orderBy('name')
            // Headcount is capped per company, so this only bounds a tenant that predates the cap.
            ->limit(self::MAX_LIMIT * 4);

        if ($search !== null) {
            $query->where(fn (Builder $inner) => $inner
                ->where('name', 'like', '%' . $search . '%')
                ->orWhere('description', 'like', '%' . $search . '%')
                ->orWhere('role', 'like', '%' . $search . '%'));
        }

        if ($capability !== null) {
            $query->whereHas(
                'selectedTools',
                fn (Builder $tools) => $tools
                    ->active()
                    ->where(fn (Builder $inner) => $inner
                        ->where('name', 'like', '%' . $capability . '%')
                        ->orWhere('description', 'like', '%' . $capability . '%'))
            );
        }

        return $query;
    }

    /**
     * @param array<int, list<string>> $projects
     * @return array<string, mixed>
     */
    private function present(Agent $agent, array $projects): array
    {
        $tools = $agent->selectedTools
            ->map(fn (CapabilityTool $tool): string => $tool->name)
            ->values();

        $row = [
            'agent_id' => $agent->getId(),
            'name' => $agent->name,
            'handle' => MentionHandle::forUser($agent->user, $this->app),
            'role' => $this->describeRole($agent),
            'agent_type' => $agent->type?->name,
            'can_execute_board_work' => $agent->canExecuteBoardWork(),
            'is_active' => (bool) $agent->is_active,
            'tool_count' => $tools->count(),
            'tools' => $tools->take(self::MAX_TOOLS_SHOWN)->all(),
            'projects' => array_slice($projects[$agent->getId()] ?? [], 0, self::MAX_PROJECTS_SHOWN),
        ];

        // Naming the caller keeps it from reading its own row as a teammate — hiring "a project
        // manager" when it IS one, or assigning itself work as though it had delegated it.
        if ($this->self()?->getId() === $agent->getId()) {
            $row['is_you'] = true;
        }

        return $row;
    }

    /**
     * `role` is a JSON-cast column that holds a plain sentence on every agent `hire_agent` created,
     * and a structured role on the ones the API created — so it reaches here as either.
     */
    private function describeRole(Agent $agent): ?string
    {
        $role = $agent->role;

        if (is_array($role)) {
            $role = implode(', ', array_filter($role, is_scalar(...)));
        }

        return Str::trimToNull(is_string($role) ? $role : null) ?? Str::trimToNull($agent->description);
    }

    /**
     * @param list<int> $agentIds
     * @return array<int, list<string>>
     */
    private function projectsByAgent(array $agentIds): array
    {
        if ($agentIds === []) {
            return [];
        }

        return ProjectMember::query()
            ->agents()
            ->whereIn('agent_id', $agentIds)
            ->notDeleted()
            ->with('project')
            ->get()
            ->filter(fn (ProjectMember $member): bool => $member->project instanceof Project
                && ! (bool) $member->project->is_deleted)
            ->groupBy('agent_id')
            ->map(fn (Collection $members): array => $members
                ->map(fn (ProjectMember $member): string => (string) $member->project->title)
                ->unique()
                ->values()
                ->all())
            ->all();
    }

    private function self(): ?Agent
    {
        return $this->callingAgent ?? $this->contextAgent();
    }

    /**
     * Whoever may hire may also see who is already on staff — the roster exists so that hire is the
     * second choice rather than the first, and gating it harder than the hire it precedes would leave
     * the duplicate hire as the only reachable move.
     *
     * @return list<string>
     */
    protected function requiredAbilities(): array
    {
        return [AgentAbilityEnum::HIRE_AGENT->value];
    }
}
