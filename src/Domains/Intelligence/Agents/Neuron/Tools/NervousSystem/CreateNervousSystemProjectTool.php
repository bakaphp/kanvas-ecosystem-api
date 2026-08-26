<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\NervousSystem\Project\Actions\AddProjectMemberAction;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Enums\ProjectMemberRoleEnum;
use Kanvas\NervousSystem\Project\Enums\ProjectStatusEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Lets the PM open a NEW project — a stream of work that doesn't belong on any project it already
 * runs. Without it the PM can only ever work the board a human created for it, so anything new gets
 * jammed into an unrelated project or dropped.
 *
 * Two things are structural rather than advisory:
 *  - **A project is born with a PM.** `CreateProjectAction` requires one, and the sane default is the
 *    caller — an agent that opens a project is accountable for it. `agent_id` hands it to someone
 *    else (typically one it just hired) without going through an update.
 *  - **Repeated wakes must not multiply projects.** A project provisions a channel, a webhook and a
 *    recurring heartbeat, so a duplicate is not a stray row — it is a second agent loop. An open
 *    project with the same title is reused, mirroring create_nervous_system_plan.
 */
#[AgentTool(name: 'Create Project', category: 'nervous_system')]
class CreateNervousSystemProjectTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct(
        private readonly ?Agent $callingAgent = null,
    ) {
        parent::__construct(
            name: 'create_nervous_system_project',
            description: 'Open a NEW project — its own board, channel and heartbeat — when a request is a '
                . 'separate stream of work that does not belong on a project you already run. You become its '
                . 'PM unless you pass agent_id. Give it an objective (the definition of done) whenever you '
                . 'know one — a project with no definition of done cannot be managed. It starts active unless '
                . 'you pass a status. Do NOT use this for work that fits an existing project — create a '
                . 'plan on that project instead.',
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
                name: 'title',
                type: PropertyType::STRING,
                description: 'Short name of the project, e.g. "Q3 website relaunch".',
                required: true,
            ),
            new ToolProperty(
                name: 'objective',
                type: PropertyType::STRING,
                description: 'The definition of done — what has to be true for this project to be finished. '
                    . 'Pass it whenever the request makes it clear; a project without one cannot be managed.',
                required: false,
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'Longer context: background, scope, constraints.',
                required: false,
            ),
            new ToolProperty(
                name: 'status',
                type: PropertyType::STRING,
                description: 'Starting status: active (default), draft, on_hold or blocked. Use draft only '
                    . 'for a project nobody should work yet.',
                required: false,
            ),
            new ToolProperty(
                name: 'agent_id',
                type: PropertyType::INTEGER,
                description: 'The agent that will manage the new project. Omit to manage it yourself.',
                required: false,
            ),
            new ToolProperty(
                name: 'deadline_at',
                type: PropertyType::STRING,
                description: 'Optional due date, ISO-8601 (e.g. 2026-09-30).',
                required: false,
            ),
            new ToolProperty(
                name: 'priority',
                type: PropertyType::INTEGER,
                description: 'Optional priority; higher runs first. Defaults to 0.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $title,
        ?string $objective = null,
        ?string $description = null,
        ?string $status = null,
        ?int $agent_id = null,
        ?string $deadline_at = null,
        ?int $priority = null,
    ): array {
        if (! $this->hasTenantContext() || $this->contextUser() === null) {
            return ['error' => 'This agent has no company context, so it cannot open a project.'];
        }

        $title = trim($title);
        if ($title === '') {
            return ['error' => 'A project needs a title.'];
        }

        $pmAgent = $this->resolvePmAgent($agent_id);
        if ($pmAgent === null) {
            return ['error' => $agent_id !== null
                ? "Agent {$agent_id} was not found in this company."
                : 'This tool does not know which agent is calling it, so it cannot decide who manages '
                    . 'the new project. Pass agent_id.',
            ];
        }

        $existing = $this->openProjectWithTitle($title);
        if ($existing !== null) {
            return [
                'project_id' => $existing->getId(),
                'title' => $existing->title,
                'objective' => $existing->objective,
                'status' => $existing->status,
                'reused' => true,
                'message' => 'A project with this title is already open. Do NOT call '
                    . 'create_nervous_system_project again for it — work on this project_id instead.',
            ];
        }

        $objective = $objective !== null && trim($objective) !== '' ? trim($objective) : null;

        // A project the PM opens is one it intends to work, so it starts running; draft is reachable
        // but has to be asked for.
        try {
            $startingStatus = $status !== null && trim($status) !== ''
                ? ProjectStatusEnum::fromAlias($status)
                : ProjectStatusEnum::ACTIVE;
        } catch (Throwable) {
            return ['error' => 'Unknown project status "' . $status . '". Valid: '
                . implode(', ', array_map(fn (ProjectStatusEnum $s): string => $s->value, ProjectStatusEnum::cases())) . '.'];
        }

        try {
            $project = new CreateProjectAction(
                ProjectData::from(
                    $this->app,
                    $this->user,
                    $this->company,
                    [
                        'title' => $title,
                        'agent_id' => $pmAgent->getId(),
                        'objective' => $objective,
                        'description' => $description,
                        'status' => $startingStatus->value,
                        'deadline_at' => $deadline_at,
                        'priority' => $priority ?? 0,
                    ],
                ),
            )->execute();
        } catch (Throwable $e) {
            // Nothing here is an expected business condition — a throw is a system fault, so it has to
            // reach Sentry even though the agent gets calm copy back.
            report($e);

            return ['error' => $e->getMessage()];
        }

        $this->seedRoster($project, $pmAgent);

        return [
            'project_id' => $project->getId(),
            'title' => $project->title,
            'objective' => $project->objective,
            'status' => $project->status,
            'pm_agent_id' => $pmAgent->getId(),
            'pm_agent' => $pmAgent->name,
            'channel_id' => $project->default_channel_id,
            'message' => $objective === null
                ? 'Created with NO objective. Ask the humans what done looks like, record it with '
                    . 'update_nervous_system_project, and do not invent work until then.'
                : 'Created. Break the objective into plans with create_nervous_system_plan and assign them.',
        ];
    }

    private function resolvePmAgent(?int $agentId): ?Agent
    {
        if ($agentId === null) {
            return $this->callingAgent;
        }

        $agent = Agent::query()
            ->where('id', $agentId)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->first();

        return $agent;
    }

    private function openProjectWithTitle(string $title): ?Project
    {
        $project = Project::query()
            ->where('title', $title)
            ->whereIn('status', ProjectStatusEnum::openStatusValues())
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->first();

        return $project;
    }

    /**
     * A project whose roster is empty has nobody to assign to and nobody to @mention — the PM would
     * have to find and add itself before it could work its own board.
     *
     * Best-effort, like the channel and webhook the same creation path provisions: the project exists
     * by now, and reporting the whole call as failed over a missing member row would have the agent
     * tell the humans nothing was created while a live, heartbeat-woken board sits there.
     */
    private function seedRoster(Project $project, Agent $pmAgent): void
    {
        try {
            $this->addMembers($project, $pmAgent);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function addMembers(Project $project, Agent $pmAgent): void
    {
        new AddProjectMemberAction(
            project: $project,
            role: ProjectMemberRoleEnum::MANAGER,
            agent: $pmAgent,
        )->execute();

        // On an autonomous wake the acting user IS the PM's own user; adding it as a human member
        // would invent a person the PM then tries to @mention.
        if ((int) $this->user->getId() === (int) $pmAgent->user_id) {
            return;
        }

        new AddProjectMemberAction(
            project: $project,
            role: ProjectMemberRoleEnum::OWNER,
            user: $this->user,
        )->execute();
    }
}
