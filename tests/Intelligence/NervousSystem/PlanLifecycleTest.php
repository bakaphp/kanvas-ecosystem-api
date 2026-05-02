<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\NervousSystem\Plan\Actions\AddTaskAction;
use Kanvas\NervousSystem\Plan\Actions\ApprovePlanAction;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\Actions\DetectStalledTasksAction;
use Kanvas\NervousSystem\Plan\Actions\UpdatePlanAction;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class PlanLifecycleTest extends TestCase
{
    public function testCreatePlanWithoutTasks(): void
    {
        [$app, $company, $user] = $this->context();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'PR2 smoke plan',
                planType: 'qualification',
                user: $user,
                description: 'Test plan with no initial tasks',
            ),
        )->execute();

        $this->assertSame('qualification', $plan->plan_type);
        $this->assertSame('draft', $plan->status);
        $this->assertSame(0, $plan->completion_pct);
        $this->assertSame(0, $plan->tasks()->count());

        $this->assertDatabaseHas(
            'nervous_system_events',
            [
                'source_entity_type' => Plan::class,
                'source_entity_id' => $plan->id,
                'event_type' => 'plan.created',
            ],
            'intelligence',
        );
    }

    public function testCreatePlanWithInitialTasksRecomputesCompletion(): void
    {
        [$app, $company, $user] = $this->context();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Plan with three tasks',
                planType: 'enrichment',
                user: $user,
            ),
            tasks: [
                new TaskData(plan: null, title: 'Step 1', sequence: 1),
                new TaskData(plan: null, title: 'Step 2', sequence: 2),
                new TaskData(plan: null, title: 'Step 3', sequence: 3),
            ],
        )->execute();

        $this->assertSame(3, $plan->tasks()->count());
        $this->assertSame(0, $plan->completion_pct);
    }

    public function testCreatePlanRejectsWhenAgentIdAndUsersIdAreBothNull(): void
    {
        [$app, $company] = $this->context();

        $this->expectException(ValidationException::class);

        new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Orphan plan',
                planType: 'custom',
            ),
        )->execute();
    }

    public function testCreatePlanWithApprovalRequiredFlipsToAwaitingApproval(): void
    {
        [$app, $company, $user] = $this->context();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Sensitive plan',
                planType: 'billing',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
                requiresHumanApproval: true,
            ),
        )->execute();

        $this->assertSame('awaiting_approval', $plan->status);
        $this->assertTrue($plan->requires_human_approval);
    }

    public function testTaskStatusTransitionRecomputesCompletionAndEmitsEvent(): void
    {
        [$app, $company, $user] = $this->context();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Two-step plan',
                planType: 'qualification',
                user: $user,
            ),
            tasks: [
                new TaskData(plan: null, title: 'Step A', sequence: 1),
                new TaskData(plan: null, title: 'Step B', sequence: 2),
            ],
        )->execute();

        $taskA = $plan->tasks()->where('sequence', 1)->firstOrFail();
        new UpdateTaskStatusAction(
            task: $taskA,
            newStatus: TaskStatusEnum::DONE,
            result: ['note' => 'finished step A'],
        )->execute();

        $plan->refresh();
        $this->assertSame(50, $plan->completion_pct);

        $this->assertDatabaseHas(
            'nervous_system_events',
            ['event_type' => 'plan.task.completed', 'source_entity_id' => $taskA->id],
            'intelligence',
        );

        $taskB = $plan->tasks()->where('sequence', 2)->firstOrFail();
        new UpdateTaskStatusAction(
            task: $taskB,
            newStatus: TaskStatusEnum::DONE,
        )->execute();

        $plan->refresh();
        $this->assertSame(100, $plan->completion_pct);
    }

    public function testTaskStatusTransitionToInProgressStampsStartedAt(): void
    {
        [$app, $company, $user] = $this->context();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Tracking plan',
                planType: 'qualification',
                user: $user,
            ),
            tasks: [new TaskData(plan: null, title: 'Task 1')],
        )->execute();

        $task = $plan->tasks()->firstOrFail();
        $this->assertNull($task->started_at);

        new UpdateTaskStatusAction(
            task: $task,
            newStatus: TaskStatusEnum::IN_PROGRESS,
        )->execute();

        $task->refresh();
        $this->assertNotNull($task->started_at);
        $this->assertNull($task->completed_at);
    }

    public function testApprovePlanFlipsAwaitingApprovalToActive(): void
    {
        [$app, $company, $user] = $this->context();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Pending plan',
                planType: 'qualification',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
                requiresHumanApproval: true,
            ),
        )->execute();

        $this->assertSame('awaiting_approval', $plan->status);

        $approved = new ApprovePlanAction(
            plan: $plan,
            reviewer: $user,
            approved: true,
            reviewOutcome: 'looks good',
        )->execute();

        $this->assertSame('active', $approved->status);
        $this->assertNotNull($approved->approved_at);
        $this->assertSame((int) $user->getId(), $approved->approved_by_user_id);
        $this->assertSame('looks good', $approved->review_outcome);
        $this->assertNotNull($approved->started_at);

        $this->assertDatabaseHas(
            'nervous_system_events',
            ['event_type' => 'plan.approved', 'source_entity_id' => $plan->id],
            'intelligence',
        );
    }

    public function testRejectPlanCancelsIt(): void
    {
        [$app, $company, $user] = $this->context();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'To be rejected',
                planType: 'qualification',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
                requiresHumanApproval: true,
            ),
        )->execute();

        $rejected = new ApprovePlanAction(
            plan: $plan,
            reviewer: $user,
            approved: false,
            reviewOutcome: 'no thanks',
        )->execute();

        $this->assertSame('cancelled', $rejected->status);
        $this->assertNotNull($rejected->completed_at);

        $this->assertDatabaseHas(
            'nervous_system_events',
            ['event_type' => 'plan.rejected', 'source_entity_id' => $plan->id],
            'intelligence',
        );
    }

    public function testApproveRejectsPlanNotInAwaitingApproval(): void
    {
        [$app, $company, $user] = $this->context();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Already active',
                planType: 'qualification',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
            ),
        )->execute();

        $this->expectException(ValidationException::class);

        new ApprovePlanAction(
            plan: $plan,
            reviewer: $user,
            approved: true,
        )->execute();
    }

    public function testUpdatePlanTransitionToActiveStampsStartedAt(): void
    {
        [$app, $company, $user] = $this->context();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Will activate',
                planType: 'qualification',
                user: $user,
            ),
        )->execute();

        $this->assertNull($plan->started_at);

        $updated = new UpdatePlanAction(
            $plan,
            new PlanData(
                app: $app,
                company: $company,
                title: $plan->title,
                planType: $plan->plan_type,
                user: $user,
                status: PlanStatusEnum::ACTIVE,
            ),
        )->execute();

        $this->assertSame('active', $updated->status);
        $this->assertNotNull($updated->started_at);
    }

    public function testAddTaskActionAppendsAndRecomputes(): void
    {
        [$app, $company, $user] = $this->context();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Growing plan',
                planType: 'qualification',
                user: $user,
            ),
            tasks: [
                new TaskData(plan: null, title: 'Step 1', sequence: 1, status: TaskStatusEnum::DONE),
            ],
        )->execute();

        $plan->refresh();
        $this->assertSame(100, $plan->completion_pct);

        new AddTaskAction(
            $plan,
            new TaskData(plan: $plan, title: 'Step 2'),
        )->execute();

        $plan->refresh();
        $this->assertSame(2, $plan->tasks()->count());
        $this->assertSame(50, $plan->completion_pct);

        $this->assertDatabaseHas(
            'nervous_system_events',
            ['event_type' => 'plan.task.created'],
            'intelligence',
        );
    }

    public function testDetectStalledTasksEmitsEventForOldInProgressTasks(): void
    {
        [$app, $company, $user] = $this->context();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Stalling plan',
                planType: 'qualification',
                user: $user,
            ),
            tasks: [new TaskData(plan: null, title: 'Slow step')],
        )->execute();

        $task = $plan->tasks()->firstOrFail();

        Carbon::setTestNow(now()->subHour());
        new UpdateTaskStatusAction(task: $task, newStatus: TaskStatusEnum::IN_PROGRESS)->execute();
        Carbon::setTestNow();

        $beforeEventCount = Event::query()
            ->where('event_type', 'plan.task.stalled')
            ->where('source_entity_id', $task->id)
            ->count();

        $result = new DetectStalledTasksAction(stalledAfterMinutes: 5)->execute();

        $this->assertGreaterThanOrEqual(1, $result['emitted']);

        $this->assertGreaterThan(
            $beforeEventCount,
            Event::query()
                ->where('event_type', 'plan.task.stalled')
                ->where('source_entity_id', $task->id)
                ->count(),
        );

        $resultSecond = new DetectStalledTasksAction(stalledAfterMinutes: 5)->execute();
        $this->assertSame(0, $resultSecond['emitted'], 'Second sweep must not re-emit for the same stalled task');
    }

    public function testPlanTenantScopingPreventsCrossAppLeak(): void
    {
        [$app, $company, $user] = $this->context();

        $minePlan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Mine',
                planType: 'qualification',
                user: $user,
            ),
        )->execute();

        // Foreign-tenant plan inserted directly to bypass DTO model lookups —
        // we don't need real Apps/Companies rows to verify the scoping.
        Plan::query()->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'apps_id' => 999997,
            'companies_id' => 999997,
            'users_id' => 999997,
            'plan_type' => 'qualification',
            'title' => 'Other',
            'status' => 'draft',
            'priority' => 0,
            'completion_pct' => 0,
            'requires_human_approval' => 0,
            'is_deleted' => 0,
            'created_at' => now(),
        ]);

        $myPlans = Plan::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->get();

        $titles = $myPlans->pluck('title')->all();
        $this->assertContains('Mine', $titles);
        $this->assertNotContains('Other', $titles);

        foreach ($myPlans as $p) {
            $this->assertSame($app->getId(), $p->apps_id);
        }

        $this->assertNotNull(
            Plan::query()->where('id', $minePlan->id)->first(),
        );
    }

    /**
     * @return array{0: Apps, 1: \Kanvas\Companies\Models\Companies, 2: Users}
     */
    private function context(): array
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        return [$app, $company, $user];
    }
}
