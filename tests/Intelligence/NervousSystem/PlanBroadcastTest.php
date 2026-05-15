<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Facades\Event;
use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\Ledger\Enums\LedgerConfigurationEnum;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\Actions\UpdatePlanAction;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Events\PlanBroadcast;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Tests\TestCase;

class PlanBroadcastTest extends TestCase
{
    public function testCreatePlanFiresPlanBroadcastWithCreatedChangeType(): void
    {
        Event::fake([PlanBroadcast::class]);

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Broadcast smoke test',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::DRAFT,
            ),
        )->execute();

        Event::assertDispatched(
            PlanBroadcast::class,
            fn (PlanBroadcast $b) =>
                $b->plan->id === $plan->id
                && $b->changeType === PlanChangeTypeEnum::CREATED
        );
    }

    public function testUpdatePlanStatusFiresBroadcastWithPreviousStatus(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Move test',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::DRAFT,
            ),
        )->execute();

        Event::fake([PlanBroadcast::class]);

        new UpdatePlanAction(
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

        Event::assertDispatched(
            PlanBroadcast::class,
            fn (PlanBroadcast $b) =>
                $b->plan->id === $plan->id
                && $b->changeType === PlanChangeTypeEnum::UPDATED
                && $b->previousStatus === 'draft'
        );
    }

    public function testTaskStatusChangeBroadcastsWithTaskAttached(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Task broadcast',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
            ),
            tasks: [
                new TaskData(plan: null, title: 'first', sequence: 0),
            ],
        )->execute();

        $task = $plan->tasks()->first();
        $this->assertNotNull($task);

        Event::fake([PlanBroadcast::class]);

        new UpdateTaskStatusAction(
            task: $task,
            newStatus: TaskStatusEnum::IN_PROGRESS,
        )->execute();

        Event::assertDispatched(
            PlanBroadcast::class,
            fn (PlanBroadcast $b) =>
                $b->plan->id === $plan->id
                && $b->changeType === PlanChangeTypeEnum::TASK_STATUS_CHANGED
                && $b->task !== null
                && $b->task->id === $task->id
        );
    }

    public function testNoBroadcastWhenAppFlagOff(): void
    {
        $app = app(Apps::class);
        $app->set(LedgerConfigurationEnum::BROADCAST_PLAN_EVENTS->value, false);

        $user = auth()->user();
        $company = $user->getCurrentCompany();

        Event::fake([PlanBroadcast::class]);

        new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'No broadcast',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::DRAFT,
            ),
        )->execute();

        Event::assertNotDispatched(PlanBroadcast::class);

        // restore default for other tests
        $app->set(LedgerConfigurationEnum::BROADCAST_PLAN_EVENTS->value, true);
    }

    public function testBroadcastChannelsIncludeWorkspacePlanAndAgentScopes(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Channel scopes',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::DRAFT,
            ),
        )->execute();

        $broadcast = new PlanBroadcast($plan, PlanChangeTypeEnum::CREATED);
        $names = array_map(fn ($c) => $c->name, $broadcast->broadcastOn());

        $cid = $company->getId();
        $aid = $app->getId();

        $this->assertContains("company-{$cid}-app-{$aid}-plans", $names);
        $this->assertContains("company-{$cid}-app-{$aid}-plan-{$plan->id}", $names);
        // No agent on this plan → no agent channel
        foreach ($names as $name) {
            $this->assertStringNotContainsString('-agent-', $name);
        }
    }

    public function testBroadcastPayloadIsLean(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Lean payload',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::DRAFT,
            ),
        )->execute();

        $payload = new PlanBroadcast($plan, PlanChangeTypeEnum::CREATED)->broadcastWith();

        // Plan payload should only carry the fields the kanban needs.
        $this->assertSame(
            ['id', 'agent_id', 'status', 'priority', 'completion_pct'],
            array_keys($payload['plan']),
        );
        $this->assertArrayHasKey('change_type', $payload);
        $this->assertArrayHasKey('previous_status', $payload);
        $this->assertSame('created', $payload['change_type']);
    }
}
