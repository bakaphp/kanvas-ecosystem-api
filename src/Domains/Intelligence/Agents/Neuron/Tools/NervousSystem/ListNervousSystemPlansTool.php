<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Support\MentionHandle;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * The board by plan rather than by member: what nobody has picked up, what is blocked, what is past
 * its deadline, what is waiting on a signature. The context bundle lists the current project's open
 * plans, so this exists for the questions that cross projects or need a filter the bundle does not
 * carry (an owner, a deadline). `list_assigned_work` is the same data keyed by one member.
 */
#[AgentTool(name: 'List Plans', category: 'nervous_system')]
class ListNervousSystemPlansTool extends Tool
{
    use HasKanvasContext;

    private const int DEFAULT_LIMIT = 25;

    private const int MAX_LIMIT = 100;

    protected string $name = 'list_nervous_system_plans';

    protected ?string $description = 'List plans across every project in the company (or one project with project_id), '
        . 'each with its owner, project, deadline, completion and open/blocked task counts. Filters: '
        . 'status (one plan status), unassigned_only (no agent and no human owns it), overdue_only '
        . '(deadline passed and not done). Defaults to open plans. Use for "what is unassigned?", '
        . '"what is blocked?", "what is overdue?", "what is awaiting approval?". For one member\'s '
        . 'work use list_assigned_work instead. Reporting only.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'project_id',
                type: PropertyType::INTEGER,
                description: 'Restrict to one project. Omit to search every project in the company.',
                required: false,
            ),
            new ToolProperty(
                name: 'status',
                type: PropertyType::STRING,
                description: 'Filter to one status: intake, draft, active, blocked, awaiting_approval, done, failed, cancelled. Omit for all OPEN plans.',
                required: false,
            ),
            new ToolProperty(
                name: 'unassigned_only',
                type: PropertyType::BOOLEAN,
                description: 'When true, return only plans with no owner (no agent_id and no assigned_users_id).',
                required: false,
            ),
            new ToolProperty(
                name: 'overdue_only',
                type: PropertyType::BOOLEAN,
                description: 'When true, return only plans whose deadline has passed and that are not done.',
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
        ?int $project_id = null,
        ?string $status = null,
        ?bool $unassigned_only = null,
        ?bool $overdue_only = null,
        ?int $limit = null,
    ): array {
        $limit = max(1, min($limit ?? self::DEFAULT_LIMIT, self::MAX_LIMIT));

        $query = Plan::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->when($project_id !== null, fn (Builder $q): Builder => $q->where('project_id', $project_id))
            ->when(
                $unassigned_only === true,
                fn (Builder $q): Builder => $q->whereNull('agent_id')->whereNull('assigned_users_id'),
            )
            ->when(
                $overdue_only === true,
                fn (Builder $q): Builder => $q
                    ->where('deadline_at', '<', Carbon::now())
                    ->whereNotIn('status', PlanStatusEnum::terminalStatusValues()),
            )
            ->with(['project', 'agent.user', 'assignedUser'])
            ->withCount([
                // Blocked is terminal to a worker but open to the PM; only done/skipped tasks are closed here.
                'tasks as open_tasks_count' => fn (Builder $t): Builder => $t->whereNotIn('status', TaskStatusEnum::completedStatusValues()),
                'tasks as blocked_tasks_count' => fn (Builder $t): Builder => $t->where('status', TaskStatusEnum::BLOCKED->value),
            ]);

        $status = $status !== null ? trim($status) : '';
        if ($status !== '') {
            try {
                $query->where('status', PlanStatusEnum::fromAlias($status)->value);
            } catch (Throwable) {
                return [
                    'status' => 'error',
                    'message' => 'Unknown plan status "' . $status . '". Valid: '
                        . implode(', ', array_map(fn (PlanStatusEnum $s): string => $s->value, PlanStatusEnum::cases())) . '.',
                ];
            }
        } else {
            $query->open();
        }

        $rows = $query
            ->orderByRaw('deadline_at IS NULL, deadline_at ASC')
            ->orderByDesc('priority')
            ->limit($limit)
            ->get()
            ->map(fn (Plan $plan): array => $this->row($plan))
            ->all();

        return [
            'status' => 'success',
            'scope' => $project_id !== null ? "project {$project_id}" : 'all projects in the company',
            'total' => count($rows),
            'unassigned_count' => count(array_filter($rows, static fn (array $row): bool => $row['owner'] === null)),
            'overdue_count' => count(array_filter($rows, static fn (array $row): bool => $row['overdue'])),
            'plans' => $rows,
            'note' => $rows === []
                ? 'No plans match. Say so plainly; do not retry with the same arguments.'
                : 'Report from these rows. plan_id is the id the other plan tools take.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Plan $plan): array
    {
        return [
            'plan_id' => $plan->getId(),
            'title' => $plan->title,
            'status' => $plan->status,
            'priority' => $plan->priority,
            'completion_pct' => $plan->completion_pct,
            'deadline_at' => $plan->deadline_at?->toDateString(),
            'overdue' => $this->isOverdue($plan),
            'project_id' => $plan->project_id,
            'project_title' => $plan->project?->title,
            'owner' => $this->owner($plan),
            'open_tasks' => (int) $plan->open_tasks_count,
            'blocked_tasks' => (int) $plan->blocked_tasks_count,
        ];
    }

    /** Same rule as the overdue_only filter, so a row never contradicts the list it came from. */
    private function isOverdue(Plan $plan): bool
    {
        return $plan->deadline_at !== null
            && $plan->deadline_at->isPast()
            && ! in_array($plan->status, PlanStatusEnum::terminalStatusValues(), true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function owner(Plan $plan): ?array
    {
        if ($plan->agent_id !== null) {
            return [
                'type' => 'agent',
                'agent_id' => $plan->agent_id,
                'name' => $plan->agent?->name,
                'handle' => MentionHandle::forUser($plan->agent?->user, $this->app),
            ];
        }

        if ($plan->assigned_users_id !== null) {
            return [
                'type' => 'user',
                'users_id' => $plan->assigned_users_id,
                'name' => $plan->assignedUser?->displayname,
                'handle' => MentionHandle::forUser($plan->assignedUser, $this->app),
            ];
        }

        return null;
    }
}
