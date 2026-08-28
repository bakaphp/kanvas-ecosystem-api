<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\WakeAgentForPlanJob;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * Putting a finished task back to `pending` means "do this again", and until now nothing acted on it.
 *
 * The wake listener only fired on terminal transitions (done/blocked/skipped), so a reset produced no
 * wake, no band dispatch, and a plan sitting `active` at 0% forever. It looked like an action, which
 * is why the agent then told a person "the runner will pick these up on the queue" — there is no
 * runner. Five tasks were reset on plan 13437 and nothing moved.
 */
class PlanTaskReopenedWakeTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = [null, 'intelligence'];

    public function test_resetting_a_finished_task_wakes_the_plan(): void
    {
        [$plan, $task] = $this->planWithTask(TaskStatusEnum::DONE);

        Bus::fake([WakeAgentForPlanJob::class]);
        $this->moveTask($plan, $task, TaskStatusEnum::PENDING, TaskStatusEnum::DONE);

        Bus::assertDispatched(
            WakeAgentForPlanJob::class,
            fn (WakeAgentForPlanJob $job): bool => $job->plan->getId() === $plan->getId()
                && $job->reason === WakeAgentForPlanJob::REASON_TASK_REOPENED,
        );
    }

    /** A reset is the opposite instruction to a completion, so it must not arrive as one. */
    public function test_a_reopened_task_does_not_wake_as_a_completion(): void
    {
        [$plan, $task] = $this->planWithTask(TaskStatusEnum::BLOCKED);

        Bus::fake([WakeAgentForPlanJob::class]);
        $this->moveTask($plan, $task, TaskStatusEnum::PENDING, TaskStatusEnum::BLOCKED);

        Bus::assertDispatched(
            WakeAgentForPlanJob::class,
            fn (WakeAgentForPlanJob $job): bool => $job->reason !== WakeAgentForPlanJob::REASON_TASK_COMPLETED,
        );
    }

    /**
     * The terminal path skips a task whose assignee IS the plan's agent — it already knows it
     * finished. A reset is the opposite case: that agent is precisely who must wake and redo it.
     */
    public function test_the_plans_own_agent_is_woken_by_a_reset_of_its_own_task(): void
    {
        [$plan, $task] = $this->planWithTask(TaskStatusEnum::DONE);
        $task->agent_id = $plan->agent_id;
        $task->saveQuietly();

        Bus::fake([WakeAgentForPlanJob::class]);
        $this->moveTask($plan, $task, TaskStatusEnum::PENDING, TaskStatusEnum::DONE);

        Bus::assertDispatched(WakeAgentForPlanJob::class);
    }

    /** A task that was never finished has not been reset, and re-saving it is not a re-run request. */
    public function test_a_task_moving_between_working_statuses_wakes_nobody(): void
    {
        [$plan, $task] = $this->planWithTask(TaskStatusEnum::IN_PROGRESS);

        Bus::fake([WakeAgentForPlanJob::class]);
        $this->moveTask($plan, $task, TaskStatusEnum::PENDING, TaskStatusEnum::IN_PROGRESS);

        Bus::assertNotDispatched(WakeAgentForPlanJob::class);
    }

    /** Sync-originated changes are the agent's own board writes coming back; waking on them loops. */
    public function test_a_reset_that_came_from_a_board_sync_does_not_wake(): void
    {
        [$plan, $task] = $this->planWithTask(TaskStatusEnum::DONE);

        Bus::fake([WakeAgentForPlanJob::class]);
        $task->status = TaskStatusEnum::PENDING->value;
        $task->saveQuietly();
        $plan->broadcastChange(
            changeType: PlanChangeTypeEnum::TASK_STATUS_CHANGED,
            task: $task,
            previousStatus: TaskStatusEnum::DONE->value,
            fromSync: true,
        );

        Bus::assertNotDispatched(WakeAgentForPlanJob::class);
    }

    /**
     * Assignment used to `saveQuietly()` and hand-dispatch `WakeWorkerForPlanJob`, so it never reached
     * this listener — the continuation loop and the task band were entered only by accident, when some
     * later task transition happened to trip it. On that path the agent marked its own tasks, and
     * forgetting left no error: plan 13765 held a researched, posted answer with its task still
     * `pending`, and a zero-error ledger.
     */
    public function test_assigning_a_plan_wakes_the_agent_that_runs_the_band(): void
    {
        $plan = $this->makePlan();
        $this->makeTask($plan, TaskStatusEnum::PENDING);

        Bus::fake([WakeAgentForPlanJob::class]);
        $plan->broadcastChange(PlanChangeTypeEnum::ASSIGNED);

        Bus::assertDispatched(
            WakeAgentForPlanJob::class,
            fn (WakeAgentForPlanJob $job): bool => $job->plan->getId() === $plan->getId()
                && $job->reason === WakeAgentForPlanJob::REASON_PLAN_ASSIGNED,
        );
    }

    /** A plan with nobody to run it has nothing to wake. */
    public function test_assigning_a_plan_with_no_agent_wakes_nobody(): void
    {
        $plan = $this->makePlan();
        $plan->agent_id = null;
        $plan->saveQuietly();

        Bus::fake([WakeAgentForPlanJob::class]);
        $plan->broadcastChange(PlanChangeTypeEnum::ASSIGNED);

        Bus::assertNotDispatched(WakeAgentForPlanJob::class);
    }

    /**
     * @return array{0: Plan, 1: Task}
     */
    private function planWithTask(TaskStatusEnum $status): array
    {
        $plan = $this->makePlan();

        return [$plan, $this->makeTask($plan, $status)];
    }

    private function moveTask(Plan $plan, Task $task, TaskStatusEnum $to, TaskStatusEnum $from): void
    {
        $task->status = $to->value;
        $task->saveQuietly();

        $plan->broadcastChange(
            changeType: PlanChangeTypeEnum::TASK_STATUS_CHANGED,
            task: $task,
            previousStatus: $from->value,
        );
    }
}
