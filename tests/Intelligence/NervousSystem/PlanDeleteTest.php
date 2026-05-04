<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Facades\Event;
use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\Actions\DeletePlanAction;
use Kanvas\NervousSystem\Plan\Actions\DeleteTaskAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Events\PlanBroadcast;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Tests\TestCase;

class PlanDeleteTest extends TestCase
{
    public function testDeletePlanSoftDeletesPlanAndTasks(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'To be deleted',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::DRAFT,
            ),
            tasks: [
                new TaskData(plan: null, title: 'task one', sequence: 0),
                new TaskData(plan: null, title: 'task two', sequence: 1),
            ],
        )->execute();

        $taskIds = $plan->tasks()->pluck('id')->all();
        $this->assertCount(2, $taskIds);

        $result = new DeletePlanAction($plan)->execute();

        $this->assertTrue($result);
        $this->assertSame(1, (int) Plan::query()->where('id', $plan->id)->value('is_deleted'));
        $this->assertSame(
            count($taskIds),
            Task::query()->whereIn('id', $taskIds)->where('is_deleted', 1)->count(),
        );
    }

    public function testDeletePlanFiresBroadcastWithDeletedChangeType(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Broadcast on delete',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::DRAFT,
            ),
        )->execute();

        Event::fake([PlanBroadcast::class]);

        new DeletePlanAction($plan)->execute();

        Event::assertDispatched(
            PlanBroadcast::class,
            fn (PlanBroadcast $b) =>
                $b->plan->id === $plan->id
                && $b->changeType === PlanBroadcast::CHANGE_DELETED
        );
    }

    public function testDeletePlanIsIdempotent(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Double delete',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::DRAFT,
            ),
        )->execute();

        new DeletePlanAction($plan)->execute();

        Event::fake([PlanBroadcast::class]);

        $second = new DeletePlanAction($plan)->execute();

        $this->assertTrue($second);
        Event::assertNotDispatched(PlanBroadcast::class);
    }

    public function testDeleteTaskRemovesItAndRecomputesPlan(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Recompute on task delete',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
            ),
            tasks: [
                new TaskData(plan: null, title: 'one', sequence: 0),
                new TaskData(plan: null, title: 'two', sequence: 1),
            ],
        )->execute();

        $first = $plan->tasks()->orderBy('sequence')->first();
        $this->assertNotNull($first);

        Event::fake([PlanBroadcast::class]);

        new DeleteTaskAction($first)->execute();

        $this->assertSame(1, (int) Task::query()->where('id', $first->id)->value('is_deleted'));

        // Plan still has 1 active task; nothing is "done" yet, so completion stays at 0
        $plan->refresh();
        $this->assertSame(0, $plan->completion_pct);

        Event::assertDispatched(
            PlanBroadcast::class,
            fn (PlanBroadcast $b) =>
                $b->plan->id === $plan->id
                && $b->changeType === PlanBroadcast::CHANGE_TASK_STATUS_CHANGED
        );
    }

    public function testDeletedTasksDoNotLeakThroughTasksRelation(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Task relation leak check',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
            ),
            tasks: [
                new TaskData(plan: null, title: 'kept', sequence: 0),
                new TaskData(plan: null, title: 'deleted', sequence: 1),
            ],
        )->execute();

        $deletedTask = $plan->tasks()->where('title', 'deleted')->first();
        $this->assertNotNull($deletedTask);

        new DeleteTaskAction($deletedTask)->execute();

        $plan->refresh();
        $titles = $plan->tasks->pluck('title')->all();

        $this->assertContains('kept', $titles);
        $this->assertNotContains('deleted', $titles);
    }

    public function testListQueryHidesSoftDeletedPlans(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Should disappear from list',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::DRAFT,
            ),
        )->execute();

        new DeletePlanAction($plan)->execute();

        $response = $this->graphQL('
            query {
                nervousSystemPlans(first: 100) {
                    data { id title }
                }
            }
        ')->assertSuccessful();

        $ids = array_column(
            $response->json('data.nervousSystemPlans.data') ?? [],
            'id',
        );

        $this->assertNotContains((string) $plan->id, $ids);
    }
}
