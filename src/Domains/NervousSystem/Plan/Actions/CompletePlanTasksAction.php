<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;

/**
 * Closes out every task still open when its plan reaches `done`. Goes one-by-one through
 * UpdateTaskStatusAction rather than a mass UPDATE so the ledger, the broadcast and the kanban push
 * happen per task exactly as they would for a manual move.
 */
class CompletePlanTasksAction
{
    public function __construct(
        protected readonly Plan $plan,
        protected readonly bool $fromSync = false,
    ) {
    }

    /** @return int how many tasks were closed */
    public function execute(): int
    {
        // Completed covers done AND skipped — a skipped task is already terminal and deliberately
        // not done, so closing the plan over it must not rewrite it.
        $open = $this->plan->tasks()
            ->whereNotIn('status', TaskStatusEnum::completedStatusValues())
            ->get();

        foreach ($open as $task) {
            new UpdateTaskStatusAction($task, TaskStatusEnum::DONE, fromSync: $this->fromSync)->execute();
        }

        // Each task recomputes completion_pct against its own `$task->plan` instance, so the caller's
        // is stale the moment the first one lands. Re-read here rather than at each call site —
        // PlanBroadcast is ShouldBroadcastNow and reads the pct straight off whatever instance it is
        // handed, so a caller that announces without this ships a done plan at the pre-cascade pct.
        if ($open->isNotEmpty()) {
            $this->plan->refresh();
        }

        return $open->count();
    }
}
