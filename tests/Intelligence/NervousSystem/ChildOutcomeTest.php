<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Plan\Actions\RecordChildOutcomeAction;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\Enums\PlanLoopConfigEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\ResumeParentTaskJob;
use Kanvas\NervousSystem\Plan\Jobs\RunTaskWorkerJob;
use Kanvas\NervousSystem\Plan\Jobs\WakeAgentForPlanJob;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Plan\Models\TaskChildOutcome;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * A child's outcome resumes its parent — once per outcome, after the outcome is committed, and never
 * by reopening a parent that already closed.
 */
class ChildOutcomeTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = [null, 'intelligence', 'social'];

    public function test_a_child_finishing_records_its_outcome_resumes_the_parent_and_queues_one_wake(): void
    {
        Bus::fake();
        $plan = $this->makePlan();
        $parent = $this->makeTask($plan, TaskStatusEnum::WAITING);
        $child = $this->makeChild($parent);

        new UpdateTaskStatusAction(
            task: $child,
            newStatus: TaskStatusEnum::DONE,
            result: ['worker_summary' => 'Counted 33 leads.'],
        )->execute();

        $outcome = TaskChildOutcome::query()->where('child_task_id', $child->getId())->sole();
        $this->assertSame($parent->getId(), $outcome->parent_task_id);
        $this->assertSame($child->current_attempt_id, $outcome->attempt_id);
        $this->assertSame(TaskStatusEnum::DONE->value, $outcome->status);
        $this->assertSame('Counted 33 leads.', $outcome->summary);
        $this->assertSame(1, $outcome->revision);
        $this->assertNull($outcome->delivered_at);
        $this->assertSame(TaskStatusEnum::IN_PROGRESS->value, $parent->refresh()->status);
        Bus::assertDispatchedTimes(ResumeParentTaskJob::class, 1);
        Bus::assertDispatched(ResumeParentTaskJob::class, fn (ResumeParentTaskJob $job): bool => $job->outcome->getId() === $outcome->getId());
    }

    public function test_two_children_finishing_produce_two_outcomes_and_two_wakes(): void
    {
        Bus::fake();
        $parent = $this->makeTask($this->makePlan(), TaskStatusEnum::WAITING);
        $first = $this->makeChild($parent);
        $second = $this->makeChild($parent);

        new UpdateTaskStatusAction(task: $first, newStatus: TaskStatusEnum::DONE)->execute();
        new UpdateTaskStatusAction(task: $second, newStatus: TaskStatusEnum::BLOCKED, blockedReason: 'no access')->execute();

        $this->assertSame(2, TaskChildOutcome::query()->where('parent_task_id', $parent->getId())->count());
        $this->assertSame('no access', TaskChildOutcome::query()->where('child_task_id', $second->getId())->sole()->summary);
        Bus::assertDispatchedTimes(ResumeParentTaskJob::class, 2);
    }

    public function test_replaying_the_same_ending_records_nothing_new(): void
    {
        Bus::fake();
        $parent = $this->makeTask($this->makePlan(), TaskStatusEnum::WAITING);
        $child = $this->makeChild($parent);

        new UpdateTaskStatusAction(task: $child, newStatus: TaskStatusEnum::DONE)->execute();
        new RecordChildOutcomeAction($child->refresh())->execute();

        $this->assertSame(1, TaskChildOutcome::query()->where('child_task_id', $child->getId())->count());
        Bus::assertDispatchedTimes(ResumeParentTaskJob::class, 1);
    }

    public function test_a_reopened_child_finishing_again_is_a_second_outcome(): void
    {
        Bus::fake();
        $parent = $this->makeTask($this->makePlan(), TaskStatusEnum::WAITING);
        $child = $this->makeChild($parent);

        new UpdateTaskStatusAction(task: $child, newStatus: TaskStatusEnum::DONE)->execute();
        new UpdateTaskStatusAction(task: $child->refresh(), newStatus: TaskStatusEnum::PENDING)->execute();
        new UpdateTaskStatusAction(task: $child->refresh(), newStatus: TaskStatusEnum::IN_PROGRESS)->execute();
        new UpdateTaskStatusAction(task: $child->refresh(), newStatus: TaskStatusEnum::DONE)->execute();

        $attempts = TaskChildOutcome::query()->where('child_task_id', $child->getId())->pluck('attempt_id');
        $this->assertCount(2, $attempts);
        $this->assertCount(2, $attempts->unique());
        Bus::assertDispatchedTimes(ResumeParentTaskJob::class, 2);
    }

    public function test_a_late_outcome_never_reopens_a_closed_parent(): void
    {
        Bus::fake();
        $parent = $this->makeTask($this->makePlan(), TaskStatusEnum::DONE);
        $child = $this->makeChild($parent);

        new UpdateTaskStatusAction(task: $child, newStatus: TaskStatusEnum::DONE)->execute();

        $outcome = TaskChildOutcome::query()->where('child_task_id', $child->getId())->sole();
        $this->assertNotNull($outcome->delivered_at);
        $this->assertSame(TaskStatusEnum::DONE->value, $parent->refresh()->status);
        $this->assertDatabaseHas(
            'nervous_system_events',
            [
                'source_entity_type' => Task::class,
                'source_entity_id' => $parent->getId(),
                'event_type' => 'plan.task.child_outcome_after_close',
            ],
            'intelligence',
        );
        Bus::assertNotDispatched(ResumeParentTaskJob::class);
    }

    public function test_the_resume_wakes_the_plan_agent_when_nobody_worked_the_parent_and_only_once(): void
    {
        Bus::fake();
        $plan = $this->makePlan();
        $parent = $this->makeTask($plan, TaskStatusEnum::WAITING);
        $child = $this->makeChild($parent, 'Audit the invoices');
        new UpdateTaskStatusAction(
            task: $child,
            newStatus: TaskStatusEnum::DONE,
            result: ['worker_summary' => 'Three were unpaid.'],
        )->execute();
        $outcome = TaskChildOutcome::query()->where('child_task_id', $child->getId())->sole();

        new ResumeParentTaskJob($outcome)->handle();
        new ResumeParentTaskJob($outcome->refresh())->handle();

        $this->assertNotNull($outcome->refresh()->delivered_at);
        Bus::assertDispatchedTimes(WakeAgentForPlanJob::class, 1);
        Bus::assertDispatched(
            WakeAgentForPlanJob::class,
            fn (WakeAgentForPlanJob $job): bool => $job->reason === WakeAgentForPlanJob::REASON_CHILD_OUTCOME
                && $job->plan->getId() === $plan->getId()
                && str_contains((string) $job->userMessage, 'Audit the invoices')
                && str_contains((string) $job->userMessage, 'Three were unpaid.'),
        );
        Bus::assertNotDispatched(RunTaskWorkerJob::class);
    }

    public function test_the_resume_runs_the_worker_again_when_a_worker_had_the_parent(): void
    {
        Bus::fake();
        $plan = $this->makePlan();
        $parent = $this->makeTask($plan, TaskStatusEnum::WAITING);
        $this->workerSessionFor($parent);
        $child = $this->makeChild($parent, 'Open the pull request');
        new UpdateTaskStatusAction(task: $child, newStatus: TaskStatusEnum::DONE)->execute();
        $outcome = TaskChildOutcome::query()->where('child_task_id', $child->getId())->sole();

        new ResumeParentTaskJob($outcome)->handle();

        Bus::assertDispatched(
            RunTaskWorkerJob::class,
            fn (RunTaskWorkerJob $job): bool => $job->task->getId() === $parent->getId()
                && str_contains((string) $job->resumeFact, 'Open the pull request'),
        );
        Bus::assertNotDispatched(WakeAgentForPlanJob::class);
    }

    /** The crash window: committed, never dispatched. The reconciler wakes the parent exactly once. */
    public function test_the_reconciler_redelivers_only_outcomes_past_the_window(): void
    {
        Bus::fake();
        $parent = $this->makeTask($this->makePlan(), TaskStatusEnum::IN_PROGRESS);
        $stale = $this->outcomeRow($parent, Carbon::now()->subMinutes(5));
        $this->outcomeRow($parent, Carbon::now());

        $this->artisan('kanvas:nervous-system:redeliver-child-outcomes')->assertSuccessful();

        Bus::assertDispatchedTimes(ResumeParentTaskJob::class, 1);
        Bus::assertDispatched(ResumeParentTaskJob::class, fn (ResumeParentTaskJob $job): bool => $job->outcome->getId() === $stale->getId());
    }

    public function test_a_worker_that_delegated_waits_and_is_resumed_by_the_outcome(): void
    {
        Bus::fake();
        $agent = $this->makeAgent();
        $agent->type->update(['handler' => SalesNeuronAgentStub::class]);
        $plan = $this->makePlan([], $agent->refresh());
        $task = $this->makeTask($plan);
        $child = $this->makeChild($task, 'Implement the endpoint', TaskStatusEnum::PENDING);

        new RunTaskWorkerJob($task)->handle();

        $this->assertSame(TaskStatusEnum::WAITING->value, $task->refresh()->status);
        $this->assertStringStartsWith('Waiting on delegated work', (string) $task->blocked_reason);
        $this->assertStringContainsString('Implement the endpoint', (string) $task->blocked_reason);
        Bus::assertNotDispatched(WakeAgentForPlanJob::class);

        new UpdateTaskStatusAction(task: $child, newStatus: TaskStatusEnum::DONE)->execute();
        $outcome = TaskChildOutcome::query()->where('child_task_id', $child->getId())->sole();
        $this->assertSame(TaskStatusEnum::IN_PROGRESS->value, $task->refresh()->status);

        new ResumeParentTaskJob($outcome)->handle();
        $resumed = null;
        Bus::assertDispatched(RunTaskWorkerJob::class, function (RunTaskWorkerJob $job) use (&$resumed): bool {
            $resumed = $job;

            return $job->resumeFact !== null;
        });

        new RunTaskWorkerJob($task->refresh(), $resumed->resumeFact)->handle();

        $this->assertSame(TaskStatusEnum::DONE->value, $task->refresh()->status);
        Bus::assertDispatched(
            WakeAgentForPlanJob::class,
            fn (WakeAgentForPlanJob $job): bool => $job->reason === WakeAgentForPlanJob::REASON_TASK_COMPLETED
                && $job->plan->getId() === $plan->getId(),
        );
    }

    public function test_a_wait_held_by_a_running_child_is_not_overdue(): void
    {
        $parent = $this->makeTask($this->makePlan(), TaskStatusEnum::WAITING);

        $this->assertSame(1, Task::query()->whereKey($parent->getId())->overdueWake()->count());
        $this->assertSame(0, Task::query()->whereKey($parent->getId())->waitingAsPlanned()->count());

        $this->makeChild($parent, status: TaskStatusEnum::IN_PROGRESS);

        $this->assertSame(0, Task::query()->whereKey($parent->getId())->overdueWake()->count());
        $this->assertSame(1, Task::query()->whereKey($parent->getId())->waitingAsPlanned()->count());
    }

    /** The outcome needs the agent: neither a skipped wait nor a band dispatch may swallow it. */
    public function test_a_child_outcome_wake_runs_a_turn_whatever_the_board_says(): void
    {
        Bus::fake();
        $agent = $this->makeAgent();
        $agent->type->update(['handler' => SalesNeuronAgentStub::class]);
        $agent->set(PlanLoopConfigEnum::CONTINUATION_ENABLED->value, '1');
        $plan = $this->makePlan([], $agent->refresh());
        $this->makeTask($plan, TaskStatusEnum::WAITING, sequence: 1);
        $this->makeTask($plan, sequence: 2);

        new WakeAgentForPlanJob($plan, WakeAgentForPlanJob::REASON_CHILD_OUTCOME, 'Task #9 is now done.')->handle();

        Bus::assertBatchCount(0);
        $this->assertDatabaseHas(
            'nervous_system_events',
            [
                'source_entity_type' => Plan::class,
                'source_entity_id' => $plan->getId(),
                'event_type' => 'plan.agent.invoked',
            ],
            'intelligence',
        );
    }

    private function makeChild(
        Task $parent,
        string $title = 'Child task',
        TaskStatusEnum $status = TaskStatusEnum::IN_PROGRESS,
    ): Task {
        return Task::create([
            'plan_id' => $parent->plan_id,
            'parent_task_id' => $parent->getId(),
            'parent_revision' => (int) $parent->revision,
            'apps_id' => $parent->apps_id,
            'companies_id' => $parent->companies_id,
            'sequence' => 0,
            'title' => $title,
            'status' => $status->value,
            'current_attempt_id' => Str::uuid()->toString(),
            'is_deleted' => 0,
        ]);
    }

    private function outcomeRow(Task $parent, Carbon $createdAt): TaskChildOutcome
    {
        $child = $this->makeChild($parent, status: TaskStatusEnum::DONE);

        return TaskChildOutcome::create([
            'apps_id' => $parent->apps_id,
            'companies_id' => $parent->companies_id,
            'parent_task_id' => $parent->getId(),
            'child_task_id' => $child->getId(),
            'attempt_id' => $child->current_attempt_id,
            'status' => TaskStatusEnum::DONE->value,
            'created_at' => $createdAt,
        ]);
    }

    private function workerSessionFor(Task $task): Session
    {
        return Session::create([
            'uuid' => 'worker-' . fake()->unique()->lexify('??????'),
            'apps_id' => $task->apps_id,
            'companies_id' => $task->companies_id,
            'entity_namespace' => Task::class,
            'entity_id' => $task->getId(),
            'agents_id' => $task->plan->agent_id,
            'channel_id' => null,
            'content' => '',
            'user' => [],
        ]);
    }
}
