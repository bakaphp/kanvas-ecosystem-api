<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Plan\Actions\CompletePlanTasksAction;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Events\PlanBroadcast;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class CompletePlanTasksActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'intelligence', 'social'];

    public function testItClosesEveryNonTerminalStatusAndLeavesSkippedAlone(): void
    {
        $plan = $this->planWithTasks([
            new TaskData(plan: null, title: 'Pending', sequence: 1),
            new TaskData(
                plan: null,
                title: 'In flight',
                sequence: 2,
                status: TaskStatusEnum::IN_PROGRESS,
            ),
            new TaskData(
                plan: null,
                title: 'Blocked',
                sequence: 3,
                status: TaskStatusEnum::BLOCKED,
            ),
            new TaskData(
                plan: null,
                title: 'Skipped',
                sequence: 4,
                status: TaskStatusEnum::SKIPPED,
            ),
            new TaskData(
                plan: null,
                title: 'Already done',
                sequence: 5,
                status: TaskStatusEnum::DONE,
            ),
        ]);

        $closed = new CompletePlanTasksAction($plan)->execute();

        $this->assertSame(3, $closed, 'Only pending, in_progress and blocked are open work.');
        $this->assertSame('done', $this->taskTitled($plan, 'Pending')->status);
        $this->assertSame('done', $this->taskTitled($plan, 'In flight')->status);
        $this->assertSame('done', $this->taskTitled($plan, 'Blocked')->status);
        $this->assertSame(
            'skipped',
            $this->taskTitled($plan, 'Skipped')->status,
            'Skipped is already terminal and deliberately not done — closing the plan must not rewrite it.'
        );
    }

    public function testItStampsCompletedAtAndEmitsTheLedgerEventPerTask(): void
    {
        $plan = $this->planWithTasks([
            new TaskData(plan: null, title: 'Pending', sequence: 1),
            new TaskData(
                plan: null,
                title: 'Blocked',
                sequence: 2,
                status: TaskStatusEnum::BLOCKED,
            ),
        ]);

        new CompletePlanTasksAction($plan)->execute();

        foreach (['Pending', 'Blocked'] as $title) {
            $task = $this->taskTitled($plan, $title);

            $this->assertNotNull($task->completed_at, "{$title} closed without a completed_at stamp.");
            $this->assertDatabaseHas(
                'nervous_system_events',
                [
                    'source_entity_type' => Task::class,
                    'source_entity_id' => $task->id,
                    'event_type' => 'plan.task.completed',
                ],
                'intelligence',
            );
        }
    }

    public function testItDrivesCompletionPctToOneHundred(): void
    {
        $plan = $this->planWithTasks([
            new TaskData(plan: null, title: 'One', sequence: 1),
            new TaskData(plan: null, title: 'Two', sequence: 2),
            new TaskData(plan: null, title: 'Three', sequence: 3),
        ]);

        $this->assertSame(0, $plan->completion_pct);

        new CompletePlanTasksAction($plan)->execute();

        $this->assertSame(100, $plan->refresh()->completion_pct);
    }

    /**
     * PlanBroadcast is ShouldBroadcastNow and reads completion_pct off the instance it is handed, so a
     * caller that announces the plan right after the cascade must not be left holding the old one.
     */
    public function testItLeavesTheCallersPlanInstanceCurrent(): void
    {
        $plan = $this->planWithTasks([
            new TaskData(plan: null, title: 'One', sequence: 1),
            new TaskData(
                plan: null,
                title: 'Two',
                sequence: 2,
                status: TaskStatusEnum::DONE,
            ),
        ]);

        $this->assertSame(50, $plan->completion_pct);

        new CompletePlanTasksAction($plan)->execute();

        $this->assertSame(100, $plan->completion_pct, 'Read without a refresh() at the call site.');
    }

    public function testItIsANoOpWhenNothingIsOpen(): void
    {
        $plan = $this->planWithTasks([
            new TaskData(
                plan: null,
                title: 'Done',
                sequence: 1,
                status: TaskStatusEnum::DONE,
            ),
            new TaskData(
                plan: null,
                title: 'Skipped',
                sequence: 2,
                status: TaskStatusEnum::SKIPPED,
            ),
        ]);

        $before = $this->taskTitled($plan, 'Done')->updated_at;

        Event::fake([PlanBroadcast::class]);

        $this->assertSame(0, new CompletePlanTasksAction($plan)->execute());

        Event::assertNotDispatched(PlanBroadcast::class);
        $this->assertEquals(
            $before,
            $this->taskTitled($plan, 'Done')->updated_at,
            'A plan with no open work must not be rewritten.'
        );
    }

    public function testItIsANoOpOnAPlanWithNoTasksAtAll(): void
    {
        $plan = $this->planWithTasks([]);

        $this->assertSame(0, new CompletePlanTasksAction($plan)->execute());
    }

    public function testItSkipsSoftDeletedTasks(): void
    {
        $plan = $this->planWithTasks([
            new TaskData(plan: null, title: 'Live', sequence: 1),
            new TaskData(plan: null, title: 'Deleted', sequence: 2),
        ]);

        $deleted = $this->taskTitled($plan, 'Deleted');
        $deleted->is_deleted = 1;
        $deleted->saveQuietly();

        $this->assertSame(1, new CompletePlanTasksAction($plan)->execute());

        // Read the row itself — the model's soft-delete scope hides it from any Task::query().
        $this->assertDatabaseHas(
            'nervous_system_tasks',
            ['id' => $deleted->id, 'status' => 'pending'],
            'intelligence',
        );
    }

    /**
     * The kanban echo guard lives on the broadcast: a board-originated close must not be pushed back
     * to the board it came from. Losing the passthrough would re-push every task of a synced plan.
     */
    public function testItPropagatesFromSyncOntoEveryTaskBroadcast(): void
    {
        $plan = $this->planWithTasks([
            new TaskData(plan: null, title: 'One', sequence: 1),
            new TaskData(plan: null, title: 'Two', sequence: 2),
        ]);

        Event::fake([PlanBroadcast::class]);

        new CompletePlanTasksAction($plan, fromSync: true)->execute();

        Event::assertDispatchedTimes(PlanBroadcast::class, 2);
        Event::assertDispatched(
            PlanBroadcast::class,
            fn (PlanBroadcast $event) => $event->fromSync === true
                && $event->changeType === PlanChangeTypeEnum::TASK_STATUS_CHANGED,
        );
    }

    public function testItBroadcastsAsANormalChangeWhenNotSyncing(): void
    {
        $plan = $this->planWithTasks([
            new TaskData(plan: null, title: 'One', sequence: 1),
        ]);

        Event::fake([PlanBroadcast::class]);

        new CompletePlanTasksAction($plan)->execute();

        Event::assertDispatched(
            PlanBroadcast::class,
            fn (PlanBroadcast $event) => $event->fromSync === false,
        );
    }

    /**
     * @param list<TaskData> $tasks
     */
    private function planWithTasks(array $tasks): Plan
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        return new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $user->getCurrentCompany(),
                title: 'Cascade subject',
                planType: 'qualification',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
            ),
            tasks: $tasks,
        )->execute();
    }

    private function taskTitled(Plan $plan, string $title): Task
    {
        return $plan->tasks()->where('title', $title)->firstOrFail();
    }
}
