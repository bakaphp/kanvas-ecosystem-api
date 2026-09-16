<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\CreateNervousSystemPlanTool;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * A plan is born in TODO whoever makes it, and moves to IN PROGRESS when its work actually starts —
 * so the board shows what is queued as well as what is running.
 */
final class PlanStartsInTodoTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    public function testAPersonsPlanIsBornInTodo(): void
    {
        $this->assertSame(PlanStatusEnum::DRAFT->value, $this->plan()->status);
    }

    public function testAnAgentsPlanIsBornInTodo(): void
    {
        $agent = $this->agent();
        $project = new CreateProjectAction(
            ProjectData::from(
                $this->app(),
                $this->currentUser(),
                $this->company(),
                ['title' => 'Agent board ' . fake()->unique()->lexify('?????'), 'agent_id' => $agent->id],
            ),
        )->execute();

        $result = new CreateNervousSystemPlanTool()
            ->withContext($this->app(), $this->company(), $this->currentUser())(
                project_id: $project->getId(),
                title: 'Draft the launch email',
            );

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(PlanStatusEnum::DRAFT->value, $result['status']);
    }

    public function testStartingTheFirstTaskMovesThePlanToInProgress(): void
    {
        $plan = $this->plan(tasks: [$this->pendingTask()]);

        $this->startFirstTask($plan);

        $plan->refresh();
        $this->assertSame(PlanStatusEnum::ACTIVE->value, $plan->status);
        $this->assertNotNull($plan->started_at);
    }

    public function testAPlanNeedingApprovalAsksForItAtBirth(): void
    {
        $plan = $this->plan(requiresHumanApproval: true);

        $this->assertSame(
            PlanStatusEnum::AWAITING_APPROVAL->value,
            $plan->status,
            'Asking only once the first task runs would mean the work began before anyone signed off.',
        );
    }

    /**
     * A draft that picks up the flag later — or predates the birth-time gate — still has to ask: starting
     * its work routes to awaiting_approval, never straight to in progress.
     */
    public function testStartingWorkOnAnUnapprovedDraftAsksForApprovalInstead(): void
    {
        $plan = $this->plan(tasks: [$this->pendingTask()]);
        $plan->requires_human_approval = true;
        $plan->saveQuietly();

        $this->startFirstTask($plan);

        $plan->refresh();
        $this->assertSame(PlanStatusEnum::AWAITING_APPROVAL->value, $plan->status);
        $this->assertNull($plan->started_at, 'Nothing has started until someone approves it.');
    }

    public function testWorkAlreadyRunningAtBirthIsInProgressFromTheStart(): void
    {
        $plan = $this->plan(tasks: [
            new TaskData(plan: null, title: 'Already dispatched', status: TaskStatusEnum::IN_PROGRESS),
        ]);

        $this->assertSame(PlanStatusEnum::ACTIVE->value, $plan->fresh()->status);
    }

    public function testStartingATaskNeverReopensAFinishedPlan(): void
    {
        $plan = $this->plan(tasks: [$this->pendingTask()]);
        $plan->update(['status' => PlanStatusEnum::DONE->value]);

        $this->startFirstTask($plan);

        $this->assertSame(PlanStatusEnum::DONE->value, $plan->fresh()->status);
    }

    /**
     * @param array<int, TaskData> $tasks
     */
    private function plan(array $tasks = [], bool $requiresHumanApproval = false): Plan
    {
        return new CreatePlanAction(
            new PlanData(
                app: $this->app(),
                company: $this->company(),
                title: 'Todo ' . fake()->unique()->lexify('?????'),
                planType: 'project_work',
                user: $this->currentUser(),
                requiresHumanApproval: $requiresHumanApproval,
            ),
            tasks: $tasks,
        )->execute();
    }

    private function pendingTask(): TaskData
    {
        return new TaskData(plan: null, title: 'First step');
    }

    private function startFirstTask(Plan $plan): void
    {
        /** @var Task $task */
        $task = $plan->tasks()->firstOrFail();

        new UpdateTaskStatusAction($task, TaskStatusEnum::IN_PROGRESS)->execute();
    }

    private function agent(): Agent
    {
        return Agent::factory()
            ->withAppId($this->app()->getId())
            ->withCompanyId($this->company()->getId())
            ->create(['user_id' => $this->currentUser()->getId()]);
    }

    private function app(): Apps
    {
        return app(Apps::class);
    }

    private function company(): Companies
    {
        return $this->currentUser()->getCurrentCompany();
    }

    private function currentUser(): Users
    {
        /** @var Users $user */
        $user = auth()->user();

        return $user;
    }
}
