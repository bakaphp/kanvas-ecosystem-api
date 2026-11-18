<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * A run that failed and was then retried or made moot leaves the board's Blocked column once the work
 * it ran for closes, and no other plan is touched on the way.
 */
class FailedRunRetirementTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = [null, 'intelligence', 'social'];

    public function test_closing_a_task_retires_the_runs_it_sent_that_failed(): void
    {
        Bus::fake();
        $parent = $this->makeTask($this->makePlan(), TaskStatusEnum::IN_PROGRESS);
        $failedRun = $this->makeRun($parent, PlanStatusEnum::FAILED, TaskStatusEnum::BLOCKED);
        $finishedRun = $this->makeRun($parent, PlanStatusEnum::DONE, TaskStatusEnum::DONE);

        new UpdateTaskStatusAction(task: $parent, newStatus: TaskStatusEnum::DONE)->execute();

        $this->assertSame(TaskStatusEnum::SKIPPED->value, $failedRun->refresh()->status);
        $this->assertSame(PlanStatusEnum::CANCELLED->value, $failedRun->plan->refresh()->status);
        $this->assertSame('Could not reach the factory', $failedRun->plan->error_message);
        $this->assertSame(PlanStatusEnum::DONE->value, $finishedRun->plan->refresh()->status);
    }

    public function test_a_failed_run_whose_task_was_dropped_afterwards_is_retired(): void
    {
        Bus::fake();
        $parent = $this->makeTask($this->makePlan(), TaskStatusEnum::DONE);
        $run = $this->makeRun($parent, PlanStatusEnum::FAILED, TaskStatusEnum::BLOCKED);

        new UpdateTaskStatusAction(task: $run, newStatus: TaskStatusEnum::SKIPPED)->execute();

        $this->assertSame(PlanStatusEnum::CANCELLED->value, $run->plan->refresh()->status);
    }

    /** The failure already woke whoever was waiting on it; retiring it is housekeeping, not news. */
    public function test_retiring_a_failed_run_wakes_nobody(): void
    {
        $parent = $this->makeTask($this->makePlan(), TaskStatusEnum::IN_PROGRESS);
        $run = $this->makeRun($parent, PlanStatusEnum::FAILED, TaskStatusEnum::BLOCKED);
        $project = new CreateProjectAction(
            ProjectData::from(
                app(Apps::class),
                static::$cachedUser,
                static::$cachedUser->getCurrentCompany(),
                ['title' => 'Board', 'agent_id' => $this->makeAgent()->getId()],
            ),
        )->execute();
        $run->plan->update(['project_id' => $project->getId()]);
        Bus::fake();

        new UpdateTaskStatusAction(task: $parent, newStatus: TaskStatusEnum::DONE)->execute();

        $this->assertSame(PlanStatusEnum::CANCELLED->value, $run->plan->refresh()->status);
        Bus::assertNotDispatched(WakeAgentForProjectJob::class);
    }

    public function test_a_failed_run_still_open_for_its_task_stays_failed(): void
    {
        Bus::fake();
        $parent = $this->makeTask($this->makePlan(), TaskStatusEnum::IN_PROGRESS);
        $run = $this->makeRun($parent, PlanStatusEnum::FAILED, TaskStatusEnum::BLOCKED);

        new UpdateTaskStatusAction(task: $parent, newStatus: TaskStatusEnum::WAITING)->execute();

        $this->assertSame(TaskStatusEnum::BLOCKED->value, $run->refresh()->status);
        $this->assertSame(PlanStatusEnum::FAILED->value, $run->plan->refresh()->status);
    }

    public function test_a_blocked_plan_is_not_a_failed_run_and_is_left_alone(): void
    {
        Bus::fake();
        $parent = $this->makeTask($this->makePlan(), TaskStatusEnum::IN_PROGRESS);
        $blockedChild = $this->makeRun($parent, PlanStatusEnum::BLOCKED, TaskStatusEnum::BLOCKED);

        new UpdateTaskStatusAction(task: $parent, newStatus: TaskStatusEnum::DONE)->execute();

        $this->assertSame(TaskStatusEnum::BLOCKED->value, $blockedChild->refresh()->status);
        $this->assertSame(PlanStatusEnum::BLOCKED->value, $blockedChild->plan->refresh()->status);
    }

    private function makeRun(Task $parent, PlanStatusEnum $planStatus, TaskStatusEnum $taskStatus): Task
    {
        $plan = $this->makePlan([
            'plan_type' => 'coding_chat',
            'status' => $planStatus->value,
            'error_message' => $planStatus === PlanStatusEnum::FAILED ? 'Could not reach the factory' : null,
        ]);

        return Task::create([
            'plan_id' => $plan->getId(),
            'parent_task_id' => $parent->getId(),
            'parent_revision' => (int) $parent->revision,
            'apps_id' => $parent->apps_id,
            'companies_id' => $parent->companies_id,
            'sequence' => 0,
            'title' => 'Factory run',
            'status' => $taskStatus->value,
            'current_attempt_id' => Str::uuid()->toString(),
            'is_deleted' => 0,
        ]);
    }
}
