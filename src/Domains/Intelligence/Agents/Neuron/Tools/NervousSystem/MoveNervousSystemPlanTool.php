<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ReportsToolOutcome;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPlanForTool;
use Kanvas\NervousSystem\Plan\Actions\UpdatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Enums\ProjectMemberTypeEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Models\ProjectMember;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Refiles a plan that landed in the wrong project — the usual cause being orchestrator routing that
 * guessed, or a human filing under whatever board was open.
 *
 * Three things travel with the plan that a bare `project_id` write would get wrong, which is why this
 * is its own verb rather than another optional field on update_plan:
 *
 *  - Ownership is scoped through project membership ({@see AssignNervousSystemPlanTool}), so an
 *    assignee who isn't a member of the destination is dropped and reported, never carried over into
 *    a project that doesn't know them.
 *  - Both projects' completion roll-ups are stale after the move, not just the destination's.
 *  - Sub-plans follow their parent. A parent in one project with its children in another is a split
 *    the board has no way to render.
 */
#[AgentTool(name: 'Move Plan', category: 'nervous_system')]
class MoveNervousSystemPlanTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ReportsToolOutcome;
    use TrackByInputs;
    use ResolvesPlanForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'move_nervous_system_plan',
            description: 'Move a plan to a different project when it was filed in the wrong one. Its tasks '
                . 'and sub-plans move with it. If the current owner is not a member of the destination '
                . 'project the plan is left unassigned — reassign it to someone who is.',
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
                name: 'plan_id',
                type: PropertyType::INTEGER,
                description: 'The plan to move.',
                required: true,
            ),
            new ToolProperty(
                name: 'project_id',
                type: PropertyType::INTEGER,
                description: 'The project to move it into, from list_nervous_system_projects.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $plan_id, int $project_id): array
    {
        $plan = $this->resolvePlanOrError($plan_id, "Plan {$plan_id} was not found in this company.");

        if (is_array($plan)) {
            return $plan;
        }

        $project = Project::query()
            ->where('id', $project_id)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->first();

        if ($project === null) {
            return ['error' => "Project {$project_id} was not found — pick a project_id from the projects list."];
        }

        if ((int) $plan->project_id === $project->getId()) {
            return $this->noop(
                [
                    'plan_id' => $plan->getId(),
                    'project_id' => $project->getId(),
                    'project_title' => $project->title,
                ],
                'This plan is ALREADY in that project — nothing to move.',
            );
        }

        $sourceProjectId = $plan->project_id;
        $droppedOwner = $this->dropOwnerIfNotMemberOf($plan, $project);

        $moved = new UpdatePlanAction(
            $plan,
            PlanData::forUpdate(
                $plan,
                $this->app,
                $this->company,
                ['project_id' => $project->getId()],
            ),
        )->execute();

        $childrenMoved = $this->moveDescendants($moved, $project);

        $project->recomputeCompletionPct();

        if ($sourceProjectId !== null) {
            Project::query()->where('id', $sourceProjectId)->first()?->recomputeCompletionPct();
        }

        return [
            'plan_id' => $moved->getId(),
            'title' => $moved->title,
            'from_project_id' => $sourceProjectId,
            'to_project_id' => $project->getId(),
            'to_project_title' => $project->title,
            'sub_plans_moved' => $childrenMoved,
            'unassigned' => $droppedOwner !== null,
            'message' => $droppedOwner !== null
                ? sprintf(
                    'Moved. %s is not a member of "%s", so the plan is now UNOWNED — assign it to a member '
                    . 'of that project, or add them to it first.',
                    $droppedOwner,
                    $project->title,
                )
                : sprintf('Moved to "%s". The move is complete — do not move it again.', $project->title),
        ];
    }

    /**
     * Clears the plan's owner when they don't belong to the destination, returning who was dropped (or
     * null when the owner carries over). Mutates in memory only — UpdatePlanAction saves the same
     * instance, so the unassign and the move land in one write inside its transaction.
     */
    private function dropOwnerIfNotMemberOf(Plan $plan, Project $project): ?string
    {
        if ($plan->agent_id !== null) {
            if ($this->isMemberOf($project, ProjectMemberTypeEnum::AGENT, 'agent_id', (int) $plan->agent_id)) {
                return null;
            }

            $name = $plan->agent?->name ?? "Agent {$plan->agent_id}";
            $plan->agent_id = null;

            return $name;
        }

        if ($plan->assigned_users_id !== null) {
            if ($this->isMemberOf($project, ProjectMemberTypeEnum::USER, 'users_id', (int) $plan->assigned_users_id)) {
                return null;
            }

            /** @var Users|null $user */
            $user = $plan->assignedUser;
            $name = $user !== null
                ? (trim($user->firstname . ' ' . $user->lastname) ?: $user->displayname)
                : "User {$plan->assigned_users_id}";
            $plan->assigned_users_id = null;

            return $name;
        }

        return null;
    }

    private function isMemberOf(
        Project $project,
        ProjectMemberTypeEnum $memberType,
        string $column,
        int $id,
    ): bool {
        return ProjectMember::query()
            ->where('project_id', $project->getId())
            ->where('member_type', $memberType->value)
            ->where($column, $id)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->exists();
    }

    /**
     * Repoints the whole sub-tree in one write per level. Deliberately a bulk update rather than a
     * recursive UpdatePlanAction: a sub-plan's project changing is not news its agent needs to be
     * woken for, and broadcasting per descendant would fan a single refile into a wake storm.
     */
    private function moveDescendants(Plan $plan, Project $project): int
    {
        $moved = 0;
        $parentIds = [$plan->getId()];

        while ($parentIds !== []) {
            // `project_id != x` is NULL-blind in SQL, and an unfiled sub-plan is exactly the one that
            // needs refiling — spell the null branch out or it silently stays behind.
            $childIds = Plan::query()
                ->whereIn('parent_plan_id', $parentIds)
                ->where(
                    fn (Builder $query): Builder => $query
                        ->whereNull('project_id')
                        ->orWhere('project_id', '!=', $project->getId())
                )
                ->fromApp($this->app)
                ->fromCompany($this->company)
                ->notDeleted()
                ->pluck('id')
                ->all();

            if ($childIds === []) {
                break;
            }

            $moved += Plan::query()->whereIn('id', $childIds)->update(['project_id' => $project->getId()]);
            $parentIds = $childIds;
        }

        return $moved;
    }
}
