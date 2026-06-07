<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions\Kanban;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\KanbanTask;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanCustomFieldEnum;
use Kanvas\Intelligence\AgentRuntime\Providers\AgentRuntimeProviderFactory;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;

/**
 * Pull one deployment's kanban slice (its agent's tasks) and mirror it into Kanvas Plans/Tasks.
 *
 * Provider-agnostic: reads the board via the runtime provider (returns normalized KanbanTask DTOs),
 * splits the task_links tree into roots (→ Plans) and children (→ Tasks), and upserts each. No event
 * watermark — `list --json` returns the whole slice and the upserts diff against the stored status,
 * so a missed run self-heals on the next tick.
 */
class SyncDeploymentKanbanAction
{
    public function __construct(
        private readonly AgentDeployment $deployment,
    ) {
    }

    /**
     * @return array{plans: int, tasks: int}
     */
    public function execute(): array
    {
        $agent = $this->deployment->agent;

        if ($agent === null) {
            return ['plans' => 0, 'tasks' => 0];
        }

        $app = $this->deployment->app;
        $company = $this->deployment->company;

        $tasks = $this->fetchBoard($app, $company);

        if ($tasks === []) {
            return ['plans' => 0, 'tasks' => 0];
        }

        /** @var array<string, KanbanTask> $byId */
        $byId = [];
        foreach ($tasks as $task) {
            $byId[$task->id] = $task;
        }

        $roots = array_filter($tasks, static fn (KanbanTask $t): bool => $t->isRoot());
        $children = array_filter($tasks, static fn (KanbanTask $t): bool => ! $t->isRoot());

        $planByExternal = $this->preloadPlans(
            $app,
            $company,
            $agent->getId()
        );

        $planCount = 0;
        foreach ($roots as $root) {
            $plan = new UpsertKanbanPlanAction(
                $root,
                $planByExternal[$root->id] ?? null,
                $this->deployment,
                $agent,
            )->execute();

            $planByExternal[$root->id] = $plan;
            $planCount++;
        }

        $taskCount = 0;
        /** @var array<int, array<string, Task>> $taskMaps */
        $taskMaps = [];
        foreach ($children as $child) {
            $rootId = $this->walkToRoot($child, $byId);
            $plan = $planByExternal[$rootId] ?? null;

            if ($plan === null) {
                // The root isn't in this agent's slice (cross-assignee dependency) — skip.
                continue;
            }

            if (! isset($taskMaps[$plan->id])) {
                $taskMaps[$plan->id] = $this->preloadTasks($plan);
            }

            $task = new UpsertKanbanTaskAction(
                $child,
                $plan,
                $taskMaps[$plan->id][$child->id] ?? null,
                $this->deployment,
                $agent,
            )->execute();

            $taskMaps[$plan->id][$child->id] = $task;
            $taskCount++;
        }

        return [
            'plans' => $planCount,
            'tasks' => $taskCount,
        ];
    }

    // Test seam — overridden to inject a canned board.
    /**
     * @return list<KanbanTask>
     */
    protected function fetchBoard(AppInterface $app, CompanyInterface $company): array
    {
        return AgentRuntimeProviderFactory::forDeployment($this->deployment)
            ->fetchKanbanBoard($this->deployment, $app, $company);
    }

    /**
     * @return array<string, Plan>
     */
    private function preloadPlans(AppInterface $app, CompanyInterface $company, int $agentId): array
    {
        $map = [];

        $plans = Plan::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->where('agent_id', $agentId)
            ->get();

        /** @var Plan $plan */
        foreach ($plans as $plan) {
            $external = $plan->get(KanbanCustomFieldEnum::TASK_ID->value);
            if (is_string($external) && $external !== '') {
                $map[$external] = $plan;
            }
        }

        return $map;
    }

    /**
     * @return array<string, Task>
     */
    private function preloadTasks(Plan $plan): array
    {
        $map = [];

        /** @var Task $task */
        foreach ($plan->tasks()->get() as $task) {
            $external = $task->get(KanbanCustomFieldEnum::TASK_ID->value);
            if (is_string($external) && $external !== '') {
                $map[$external] = $task;
            }
        }

        return $map;
    }

    /**
     * Walk parent edges to the root ancestor (v1 flattens depth > 2). Stops at a parent missing
     * from the slice (treats it as the root) and caps hops to guard against a cyclic graph.
     *
     * @param array<string, KanbanTask> $byId
     */
    private function walkToRoot(KanbanTask $task, array $byId): string
    {
        $current = $task;
        $hops = 0;

        while ($current->parentIds !== [] && $hops < 10) {
            $parentId = $current->parentIds[0];

            if (! isset($byId[$parentId])) {
                return $parentId;
            }

            $current = $byId[$parentId];
            $hops++;
        }

        return $current->id;
    }
}
