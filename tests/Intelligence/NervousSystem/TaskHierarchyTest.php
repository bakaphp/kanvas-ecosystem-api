<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\AddNervousSystemTaskTool;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Plan\Actions\CreateAgentRunPlanAction;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\RunTaskWorkerJob;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Plan\Support\DelegationOrigin;
use Kanvas\Users\Models\Users;
use ReflectionMethod;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * A task that delegates points its child back at itself, and every run of a task has an identity of
 * its own — the two facts a child's outcome needs to resume the right parent exactly once.
 */
class TaskHierarchyTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = [null, 'intelligence', 'social'];

    public function test_entering_in_progress_mints_an_attempt_and_reopening_mints_a_new_one(): void
    {
        $task = $this->makeTask($this->makePlan());

        new UpdateTaskStatusAction(task: $task, newStatus: TaskStatusEnum::IN_PROGRESS)->execute();
        $first = $task->refresh()->current_attempt_id;

        new UpdateTaskStatusAction(task: $task, newStatus: TaskStatusEnum::DONE)->execute();
        new UpdateTaskStatusAction(task: $task->refresh(), newStatus: TaskStatusEnum::PENDING)->execute();
        new UpdateTaskStatusAction(task: $task->refresh(), newStatus: TaskStatusEnum::IN_PROGRESS)->execute();

        $this->assertNotNull($first);
        $this->assertNotNull($task->refresh()->current_attempt_id);
        $this->assertNotSame($first, $task->current_attempt_id);
    }

    public function test_a_wait_resuming_keeps_its_attempt(): void
    {
        $task = $this->makeTask($this->makePlan());

        new UpdateTaskStatusAction(task: $task, newStatus: TaskStatusEnum::IN_PROGRESS)->execute();
        $attempt = $task->refresh()->current_attempt_id;

        new UpdateTaskStatusAction(
            task: $task,
            newStatus: TaskStatusEnum::WAITING,
            blockedReason: 'tomorrow',
            wakeAt: Carbon::now()->addDay(),
        )->execute();
        new UpdateTaskStatusAction(task: $task->refresh(), newStatus: TaskStatusEnum::IN_PROGRESS)->execute();

        $this->assertSame($attempt, $task->refresh()->current_attempt_id);
    }

    public function test_completing_clears_the_reason_from_the_block_it_came_out_of(): void
    {
        $task = $this->makeTask($this->makePlan(), TaskStatusEnum::BLOCKED, blockedReason: 'no tool for that');

        new UpdateTaskStatusAction(task: $task, newStatus: TaskStatusEnum::DONE)->execute();

        $this->assertNull($task->refresh()->blocked_reason);
    }

    /** The worker used to save quietly, which skipped everything the transition owns. */
    public function test_the_worker_writes_through_the_transition(): void
    {
        $task = $this->makeTask($this->makePlan(), TaskStatusEnum::IN_PROGRESS);

        $job = new RunTaskWorkerJob($task);
        new ReflectionMethod($job, 'markDone')->invoke($job, 'Delivered.');

        $this->assertSame(TaskStatusEnum::DONE->value, $task->refresh()->status);
        $this->assertSame('Delivered.', $task->result['worker_summary']);
        $this->assertDatabaseHas(
            'nervous_system_events',
            [
                'source_entity_type' => Task::class,
                'source_entity_id' => $task->getId(),
                'event_type' => 'plan.task.completed',
            ],
            'intelligence',
        );
    }

    public function test_a_run_delegated_from_a_task_links_both_the_task_and_the_plan(): void
    {
        $plan = $this->makePlan(['project_id' => 4242]);
        $parent = $this->makeTask($plan, TaskStatusEnum::IN_PROGRESS);

        $child = new CreateAgentRunPlanAction(
            agent: $plan->agent,
            brief: 'Fix the login redirect',
            planType: 'coding_job',
            parentTask: $parent,
        )->execute();

        $this->assertSame($parent->getId(), $child->parent_task_id);
        $this->assertSame(1, $child->parent_revision);
        $this->assertNotNull($child->current_attempt_id);
        $this->assertSame($plan->getId(), $child->plan->parent_plan_id);
        $this->assertSame(4242, $child->plan->project_id);
    }

    public function test_the_delegation_origin_is_read_off_the_session_entity(): void
    {
        $plan = $this->makePlan();
        $task = $this->makeTask($plan, TaskStatusEnum::IN_PROGRESS);

        $fromWorker = DelegationOrigin::fromSession($this->sessionFor(Task::class, $task->getId(), $plan));
        $fromPlanWake = DelegationOrigin::fromSession($this->sessionFor(Plan::class, $plan->getId(), $plan));
        $fromChat = DelegationOrigin::fromSession($this->sessionFor(Users::class, static::$cachedUser->getId(), $plan));

        $this->assertSame($task->getId(), $fromWorker->task?->getId());
        $this->assertSame($plan->getId(), $fromWorker->plan?->getId());
        $this->assertNull($fromPlanWake->task);
        $this->assertSame($plan->getId(), $fromPlanWake->plan?->getId());
        $this->assertNull($fromChat->task);
        $this->assertNull($fromChat->plan);
        $this->assertNull(DelegationOrigin::fromSession(null)->plan);
    }

    public function test_the_add_task_tool_links_a_sub_task_to_its_parent_on_the_same_plan(): void
    {
        $plan = $this->makePlan();
        $parent = $this->makeTask($plan, TaskStatusEnum::IN_PROGRESS);
        $otherPlan = $this->makePlan([], $plan->agent);
        $tool = new AddNervousSystemTaskTool()->withContext(
            app(Apps::class),
            static::$cachedUser->getCurrentCompany(),
            static::$cachedUser,
        );

        $linked = $tool(plan_id: $plan->getId(), title: 'Count the leads', parent_task_id: $parent->getId());
        $crossPlan = $tool(plan_id: $otherPlan->getId(), title: 'Count the leads elsewhere', parent_task_id: $parent->getId());

        $this->assertSame($parent->getId(), $linked['parent_task_id']);
        $this->assertSame($parent->getId(), Task::query()->findOrFail($linked['task_id'])->parent_task_id);
        $this->assertArrayHasKey('error', $crossPlan);
        $this->assertStringContainsString('another plan', $crossPlan['error']);
    }

    private function sessionFor(string $namespace, int $entityId, Plan $plan): Session
    {
        return Session::create([
            'uuid' => 'origin-' . fake()->unique()->lexify('??????'),
            'apps_id' => $plan->apps_id,
            'companies_id' => $plan->companies_id,
            'entity_namespace' => $namespace,
            'entity_id' => $entityId,
            'agents_id' => $plan->agent_id,
            'channel_id' => null,
            'content' => '',
            'user' => [],
        ]);
    }
}
