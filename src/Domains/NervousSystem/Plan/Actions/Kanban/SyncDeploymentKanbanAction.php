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
 * Mirror one deployment's kanban into Kanvas Plans/Tasks. Two sources, merged by task id:
 *  - **Refresh** every card Kanvas already tracks (by `AGENT_KANBAN_TASK_ID` + agent) via `show <id>` —
 *    assignee-agnostic, so a card reassigned away from the agent's profile still syncs back.
 *  - **Discover** new cards the agent created via `list --assignee` (the board slice).
 * The merged set is split into roots (→ Plans) and children (→ Tasks) and upserted (status-diffed),
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

        $planByExternal = $this->preloadPlans($app, $company, $agent->getId());
        $taskByExternal = $this->preloadTasks($planByExternal);

        // Discover new cards via the assignee-filtered board slice...
        /** @var array<string, KanbanTask> $byId */
        $byId = [];
        foreach ($this->fetchBoard($app, $company) as $card) {
            $byId[$card->id] = $card;
        }

        // ...then refresh any card we already track that the slice missed (reassigned / profile
        // mismatch). Matching is by task id + agent, NOT by the current assignee.
        foreach ([...array_keys($planByExternal), ...array_keys($taskByExternal)] as $knownId) {
            if (isset($byId[$knownId])) {
                continue;
            }

            $card = $this->fetchTask($knownId);
            if ($card !== null) {
                $byId[$card->id] = $card;
            }
        }

        if ($byId === []) {
            return ['plans' => 0, 'tasks' => 0];
        }

        $all = array_values($byId);
        $roots = array_filter($all, static fn (KanbanTask $t): bool => $t->isRoot());
        $children = array_filter($all, static fn (KanbanTask $t): bool => ! $t->isRoot());

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
        foreach ($children as $child) {
            $rootId = $this->walkToRoot($child, $byId);
            $plan = $planByExternal[$rootId] ?? null;

            if ($plan === null) {
                // The root isn't in this agent's slice (cross-assignee dependency) — skip.
                continue;
            }

            $task = new UpsertKanbanTaskAction(
                $child,
                $plan,
                $taskByExternal[$child->id] ?? null,
                $this->deployment,
                $agent,
            )->execute();

            $taskByExternal[$child->id] = $task;
            $taskCount++;
        }

        return [
            'plans' => $planCount,
            'tasks' => $taskCount,
        ];
    }

    // Test seam — discovery of new cards (assignee-filtered board slice).
    /**
     * @return list<KanbanTask>
     */
    protected function fetchBoard(AppInterface $app, CompanyInterface $company): array
    {
        return AgentRuntimeProviderFactory::forDeployment($this->deployment)
            ->fetchKanbanBoard($this->deployment, $app, $company);
    }

    // Test seam — refresh a single known card by id (assignee-agnostic).
    protected function fetchTask(string $externalTaskId): ?KanbanTask
    {
        return AgentRuntimeProviderFactory::forDeployment($this->deployment)
            ->fetchKanbanTask($this->deployment, $this->deployment->app, $this->deployment->company, $externalTaskId);
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
     * @param array<string, Plan> $planByExternal
     * @return array<string, Task>
     */
    private function preloadTasks(array $planByExternal): array
    {
        $map = [];

        foreach ($planByExternal as $plan) {
            /** @var Task $task */
            foreach ($plan->tasks()->get() as $task) {
                $external = $task->get(KanbanCustomFieldEnum::TASK_ID->value);
                if (is_string($external) && $external !== '') {
                    $map[$external] = $task;
                }
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
