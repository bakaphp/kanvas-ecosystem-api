<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\SnoozeNervousSystemTaskTool;
use Kanvas\NervousSystem\Plan\Actions\SnoozeTaskAction;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\RunTaskWorkerJob;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionStatusEnum;
use Kanvas\NervousSystem\Scheduling\Jobs\RunScheduledAgentActionJob;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;
use Kanvas\NervousSystem\Scheduling\Services\ScheduledActionTimezoneResolver;
use ReflectionMethod;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * A wait is a task parked with a booked wake. The wake belongs to the task: snoozing again replaces
 * it, closing the task cancels it, and the wake itself is what reopens the task.
 */
class SnoozeTaskTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = [null, 'intelligence', 'social'];

    public function test_snoozing_parks_the_task_and_books_one_wake_that_points_at_it(): void
    {
        $plan = $this->makePlan();
        $task = $this->makeTask($plan, TaskStatusEnum::IN_PROGRESS);
        $until = Carbon::now()->addDay()->setTime(9, 0);

        $wake = new SnoozeTaskAction(
            task: $task,
            agent: $plan->agent,
            until: $until,
            reason: 'Charlie: will send the strategy tomorrow',
        )->execute();

        $task->refresh();
        $this->assertSame(TaskStatusEnum::WAITING->value, $task->status);
        $this->assertTrue($until->equalTo($task->wake_at));
        $this->assertSame('Charlie: will send the strategy tomorrow', $task->blocked_reason);

        $this->assertSame(ScheduledActionStatusEnum::PENDING->value, $wake->status);
        $this->assertSame(Task::class, $wake->source_entity_type);
        $this->assertSame((string) $task->getId(), $wake->source_entity_id);
        $this->assertTrue($until->equalTo($wake->run_at));
        $this->assertStringContainsString('Task #' . $task->getId(), $wake->payload['instruction']);
        $this->assertStringContainsString('Charlie: will send the strategy tomorrow', $wake->payload['instruction']);
        $this->assertSame(1, $this->pendingWakesFor($task)->count());
    }

    public function test_snoozing_again_replaces_the_earlier_wake(): void
    {
        $plan = $this->makePlan();
        $task = $this->makeTask($plan, TaskStatusEnum::IN_PROGRESS);

        $first = new SnoozeTaskAction(
            task: $task,
            agent: $plan->agent,
            until: Carbon::now()->addDay(),
            reason: 'tomorrow',
        )->execute();
        $second = new SnoozeTaskAction(
            task: $task,
            agent: $plan->agent,
            until: Carbon::now()->addDays(4),
            reason: 'actually Friday',
        )->execute();

        $this->assertSame(ScheduledActionStatusEnum::CANCELLED->value, $first->refresh()->status);
        $this->assertSame([$second->getId()], $this->pendingWakesFor($task)->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertTrue($second->run_at->equalTo($task->refresh()->wake_at));
    }

    public function test_closing_the_task_cancels_its_wake(): void
    {
        $plan = $this->makePlan();
        $task = $this->makeTask($plan, TaskStatusEnum::IN_PROGRESS);
        $wake = new SnoozeTaskAction(
            task: $task,
            agent: $plan->agent,
            until: Carbon::now()->addDay(),
            reason: 'tomorrow',
        )->execute();

        new UpdateTaskStatusAction(task: $task->refresh(), newStatus: TaskStatusEnum::DONE)->execute();

        $this->assertSame(ScheduledActionStatusEnum::CANCELLED->value, $wake->refresh()->status);
        $this->assertNull($task->refresh()->wake_at);
        $this->assertSame(0, $this->pendingWakesFor($task)->count());
    }

    /** The worker's quiet done-write bypasses UpdateTaskStatusAction, so it must cancel on its own. */
    public function test_a_worker_finishing_the_task_quietly_still_cancels_its_wake(): void
    {
        $plan = $this->makePlan();
        $task = $this->makeTask($plan, TaskStatusEnum::IN_PROGRESS);
        $wake = new SnoozeTaskAction(
            task: $task,
            agent: $plan->agent,
            until: Carbon::now()->addDay(),
            reason: 'tomorrow',
        )->execute();

        $job = new RunTaskWorkerJob($task->refresh());
        new ReflectionMethod($job, 'markDone')->invoke($job, 'Delivered.');

        $this->assertSame(ScheduledActionStatusEnum::CANCELLED->value, $wake->refresh()->status);
        $this->assertSame(TaskStatusEnum::DONE->value, $task->refresh()->status);
    }

    /** The wake is what ends the wait: the task is back in progress before the agent runs on it. */
    public function test_the_wake_reopens_the_task_before_the_agent_runs(): void
    {
        $plan = $this->makePlan();
        $agent = $plan->agent;
        $agent->type->update(['handler' => SalesNeuronAgentStub::class]);
        $task = $this->makeTask($plan, TaskStatusEnum::IN_PROGRESS);
        $wake = new SnoozeTaskAction(
            task: $task,
            agent: $agent,
            until: Carbon::now()->addDay(),
            reason: 'tomorrow',
        )->execute();

        $wake->run_at = Carbon::now()->subMinute();
        $wake->saveOrFail();

        new RunScheduledAgentActionJob($agent->app, $agent->company, $wake)->handle();

        $this->assertSame(TaskStatusEnum::IN_PROGRESS->value, $task->refresh()->status);
        $this->assertNull($task->wake_at);
        $this->assertSame(ScheduledActionStatusEnum::EXECUTED->value, $wake->refresh()->status);
    }

    public function test_the_tool_defaults_to_tomorrow_morning_in_the_company_timezone(): void
    {
        $plan = $this->makePlan();
        $task = $this->makeTask($plan, TaskStatusEnum::IN_PROGRESS);
        $timezone = new ScheduledActionTimezoneResolver()->resolve($plan->agent->company, static::$cachedUser);

        $result = $this->tool($plan)->__invoke(task_id: $task->getId(), reason: 'Charlie said tomorrow');

        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame(TaskStatusEnum::WAITING->value, $result['status']);
        $this->assertSame($timezone, $result['timezone']);
        $this->assertSame(Carbon::tomorrow($timezone)->setTime(9, 0)->format('Y-m-d H:i'), $result['wake_at']);
        $this->assertTrue(Carbon::tomorrow($timezone)->setTime(9, 0)->utc()->equalTo($task->refresh()->wake_at));
    }

    public function test_the_tool_refuses_a_finished_task_and_a_bad_date(): void
    {
        $plan = $this->makePlan();
        $done = $this->makeTask($plan, TaskStatusEnum::DONE);
        $open = $this->makeTask($plan, TaskStatusEnum::PENDING);

        $refused = $this->tool($plan)->__invoke(task_id: $done->getId(), reason: 'waiting');
        $this->assertFalse($refused['success']);
        $this->assertSame('denied', $refused['outcome']);

        $badDate = $this->tool($plan)->__invoke(task_id: $open->getId(), reason: 'waiting', until: 'next Tuesday-ish');
        $this->assertFalse($badDate['success']);
        $this->assertSame(TaskStatusEnum::PENDING->value, $open->refresh()->status);

        $missing = $this->tool($plan)->__invoke(task_id: 999999999, reason: 'waiting');
        $this->assertSame('not_found', $missing['outcome']);
    }

    private function tool($plan): SnoozeNervousSystemTaskTool
    {
        $agent = $plan->agent;

        return new SnoozeNervousSystemTaskTool($agent)->withContext(app(Apps::class), $agent->company, static::$cachedUser);
    }

    private function pendingWakesFor(Task $task)
    {
        return ScheduledAction::query()
            ->where('source_entity_type', Task::class)
            ->where('source_entity_id', (string) $task->getId())
            ->where('status', ScheduledActionStatusEnum::PENDING->value)
            ->get();
    }
}
