<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Plan\Support\MentionHandle;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Everything one member owns across the company's boards. The context bundle a PM reads each turn
 * covers one project, so "what is X working on?" needs a lookup that crosses them.
 *
 * A human owns work through Plan.assigned_users_id (or is owed an answer through
 * Task.waiting_on_users_id); an agent through Plan.agent_id / Task.agent_id. Tasks have no human
 * assignee column, so a person's tasks are the open tasks of the plans they own.
 */
#[AgentTool(name: 'List Assigned Work', category: 'nervous_system')]
class ListAssignedWorkTool extends Tool
{
    use HasKanvasContext;

    private const int DEFAULT_LIMIT = 25;

    private const int MAX_LIMIT = 100;

    protected string $name = 'list_assigned_work';

    protected ?string $description = 'List the plans and tasks assigned to ONE member — a human (users_id) or an agent '
        . '(agent_id), pass exactly one — across every project in the company, or one project when '
        . 'project_id is given. Use it to answer "what is X working on?", "does X have anything '
        . 'assigned?", "what is on X\'s plate?" BEFORE saying someone has nothing: the context '
        . 'bundle does not show who a plan is assigned to. Defaults to open work; pass '
        . 'include_closed to see finished plans too. Reporting only.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'users_id',
                type: PropertyType::INTEGER,
                description: 'The HUMAN member (users_id from the members list). Omit when asking about an agent.',
                required: false,
            ),
            new ToolProperty(
                name: 'agent_id',
                type: PropertyType::INTEGER,
                description: 'The AGENT member (agent_id from the members list). Omit when asking about a human.',
                required: false,
            ),
            new ToolProperty(
                name: 'project_id',
                type: PropertyType::INTEGER,
                description: 'Restrict to one project. Omit to search every project in the company.',
                required: false,
            ),
            new ToolProperty(
                name: 'include_closed',
                type: PropertyType::BOOLEAN,
                description: 'When true, also return done/failed/cancelled plans and their tasks. Default false (open work only).',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max plans to return (default 25, max 100).',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?int $users_id = null,
        ?int $agent_id = null,
        ?int $project_id = null,
        ?bool $include_closed = null,
        ?int $limit = null,
    ): array {
        if (($users_id === null) === ($agent_id === null)) {
            return [
                'status' => 'error',
                'message' => 'Provide exactly one of users_id or agent_id (from the project members).',
            ];
        }

        $limit = max(1, min($limit ?? self::DEFAULT_LIMIT, self::MAX_LIMIT));
        $includeClosed = $include_closed === true;

        $member = $users_id !== null
            ? $this->resolveUser($users_id)
            : $this->resolveAgent($agent_id);

        if (isset($member['status'])) {
            return $member;
        }

        $plans = $this->plansQuery($users_id, $agent_id, $project_id, $includeClosed)
            ->limit($limit)
            ->get();

        $waitingOn = $users_id !== null
            ? $this->tasksWaitingOn($users_id, $project_id)
            : collect();

        $rows = $plans
            ->map(fn (Plan $plan): array => $this->planRow($plan, $agent_id, $includeClosed))
            ->all();

        return [
            'status' => 'success',
            'member' => $member,
            'scope' => $project_id !== null ? "project {$project_id}" : 'all projects in the company',
            'total_plans' => count($rows),
            'total_tasks' => array_sum(array_map(static fn (array $row): int => count($row['tasks']), $rows)),
            'plans' => $rows,
            'tasks_waiting_on_them' => $waitingOn->all(),
            'note' => $rows === [] && $waitingOn->isEmpty()
                ? 'Nothing is assigned to this member' . ($includeClosed ? '' : ' (open work only; pass include_closed to see finished plans)') . '. '
                    . 'Say so plainly; do not retry with the same arguments.'
                : 'Report from these rows. plan_id/task_id are the ids the other tools take.',
        ];
    }

    private function plansQuery(
        ?int $usersId,
        ?int $agentId,
        ?int $projectId,
        bool $includeClosed,
    ): Builder {
        return Plan::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->when($usersId !== null, fn (Builder $q): Builder => $q->where('assigned_users_id', $usersId))
            ->when(
                $agentId !== null,
                fn (Builder $q): Builder => $q->where(
                    fn (Builder $owner): Builder => $owner
                        ->where('agent_id', $agentId)
                        ->orWhereHas('tasks', fn (Builder $t): Builder => $t->where('agent_id', $agentId)),
                ),
            )
            ->when($projectId !== null, fn (Builder $q): Builder => $q->where('project_id', $projectId))
            ->when(! $includeClosed, fn (Builder $q): Builder => $q->open())
            ->with(['tasks', 'project', 'agent', 'assignedUser'])
            ->orderByDesc('priority')
            ->orderByDesc('updated_at');
    }

    /**
     * @return array<string, mixed>
     */
    private function planRow(Plan $plan, ?int $agentId, bool $includeClosed): array
    {
        // A plan listed because the agent holds one of its tasks shows only that agent's tasks; a
        // plan the member owns outright shows all of them.
        $ownsPlan = $agentId === null || (int) $plan->agent_id === $agentId;

        $tasks = $plan->tasks
            ->when(! $ownsPlan, fn ($tasks) => $tasks->where('agent_id', $agentId))
            // Blocked is terminal to a worker but open to the PM; only done/skipped tasks leave the list.
            ->when(! $includeClosed, fn ($tasks) => $tasks->whereNotIn('status', TaskStatusEnum::completedStatusValues()))
            ->values()
            ->map(fn (Task $task): array => [
                'task_id' => $task->getId(),
                'title' => $task->title,
                'status' => $task->status,
                'agent_id' => $task->agent_id,
                'blocked_reason' => $task->blocked_reason,
            ])
            ->all();

        return [
            'plan_id' => $plan->getId(),
            'title' => $plan->title,
            'status' => $plan->status,
            'completion_pct' => $plan->completion_pct,
            'priority' => $plan->priority,
            'project_id' => $plan->project_id,
            'project_title' => $plan->project?->title,
            'owned_by' => $ownsPlan ? 'member' : 'someone else (member holds a task on it)',
            'assigned_users_id' => $plan->assigned_users_id,
            'agent_id' => $plan->agent_id,
            'tasks' => $tasks,
        ];
    }

    /**
     * Open tasks parked on this person for an answer: not theirs to execute, but theirs to unblock.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function tasksWaitingOn(int $usersId, ?int $projectId): Collection
    {
        return Task::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->waiting()
            ->where('waiting_on_users_id', $usersId)
            ->when(
                $projectId !== null,
                fn (Builder $q): Builder => $q->whereHas('plan', fn (Builder $p): Builder => $p->where('project_id', $projectId)),
            )
            ->with('plan')
            ->limit(self::MAX_LIMIT)
            ->get()
            ->map(fn (Task $task): array => [
                'task_id' => $task->getId(),
                'title' => $task->title,
                'status' => $task->status,
                'plan_id' => $task->plan_id,
                'plan_title' => $task->plan?->title,
                'project_id' => $task->plan?->project_id,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveUser(int $usersId): array
    {
        // Membership is read through UsersAssociatedCompanies rather than the repository's users-join,
        // which runs on the `mysql` connection and cannot see an `ecosystem` row inside a transaction.
        try {
            $user = Users::getById($usersId);
            UsersRepository::belongsToCompany($user, $this->company);
        } catch (ModelNotFoundException) {
            return [
                'status' => 'error',
                'message' => "User {$usersId} is not a member of this company — pick a users_id from the project members.",
            ];
        }

        return [
            'type' => 'user',
            'users_id' => $user->getId(),
            'name' => trim($user->firstname . ' ' . $user->lastname) ?: $user->displayname,
            'handle' => MentionHandle::forUser($user, $this->app),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveAgent(int $agentId): array
    {
        $agent = Agent::query()
            ->where('id', $agentId)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->first();

        if ($agent === null) {
            return [
                'status' => 'error',
                'message' => "Agent {$agentId} was not found — pick an agent_id from the project members.",
            ];
        }

        return [
            'type' => 'agent',
            'agent_id' => $agent->getId(),
            'name' => $agent->name,
            'handle' => MentionHandle::forUser($agent->user, $this->app),
        ];
    }
}
