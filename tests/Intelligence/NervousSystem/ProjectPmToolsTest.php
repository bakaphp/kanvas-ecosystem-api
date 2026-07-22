<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\ProjectManagement\ProjectManagerAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\AssignNervousSystemTaskTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\CreateNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\DeleteNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\DeleteNervousSystemTaskTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\UpdateNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\UpdateNervousSystemProjectTool;
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
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForTaskJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;
use ReflectionMethod;
use Tests\TestCase;

class ProjectPmToolsTest extends TestCase
{
    /**
     * @return array{0: Apps, 1: Companies, 2: Users}
     */
    private function context(): array
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        return [$app, $user->getCurrentCompany(), $user];
    }

    private function makeAgent(Apps $app, Companies $company, Users $user): Agent
    {
        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId(), 'is_active' => true]);
    }

    private function makeProject(Apps $app, Companies $company, Users $user): Project
    {
        return new CreateProjectAction(
            ProjectData::from(
                $app,
                $user,
                $company,
                ['title' => 'PM tools project', 'agent_id' => $this->makeAgent($app, $company, $user)->id],
            ),
        )->execute();
    }

    private function planUnderProject(Project $project, Apps $app, Companies $company, Users $user): Plan
    {
        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Build',
                planType: 'project_work',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
            ),
            tasks: [new TaskData(plan: null, title: 'task one', sequence: 0)],
        )->execute();

        $plan->project_id = $project->id;
        $plan->saveQuietly();

        return $plan;
    }

    public function testCreatePlanToolCreatesPlanUnderProject(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);

        $tool = new CreateNervousSystemPlanTool()->withContext($app, $company, $user);
        $result = $tool((int) $project->id, 'Marketing site', 'ship the redesign');

        $this->assertSame((int) $project->id, (int) $result['project_id']);
        $this->assertSame(
            (int) $project->id,
            (int) Plan::query()->where('id', $result['plan_id'])->value('project_id'),
        );
    }

    public function testAssignTaskToolSetsExecutor(): void
    {
        Bus::fake([WakeAgentForTaskJob::class]);

        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $plan = $this->planUnderProject($project, $app, $company, $user);
        /** @var Task $task */
        $task = $plan->tasks()->firstOrFail();

        $executor = $this->makeAgent($app, $company, $user);

        $tool = new AssignNervousSystemTaskTool()->withContext($app, $company, $user);
        $result = $tool((int) $task->id, (int) $executor->id);

        $this->assertSame((int) $executor->id, (int) $result['agent_id']);
        $this->assertSame((int) $executor->id, (int) Task::query()->where('id', $task->id)->value('agent_id'));
    }

    public function testAssignTaskToolWakesAssigneeAgent(): void
    {
        Bus::fake([WakeAgentForTaskJob::class]);

        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $plan = $this->planUnderProject($project, $app, $company, $user);
        /** @var Task $task */
        $task = $plan->tasks()->firstOrFail();

        $executor = $this->makeAgent($app, $company, $user);

        $tool = new AssignNervousSystemTaskTool()->withContext($app, $company, $user);
        $tool((int) $task->id, (int) $executor->id);

        Bus::assertDispatched(
            WakeAgentForTaskJob::class,
            fn (WakeAgentForTaskJob $job): bool => (int) $job->task->getId() === (int) $task->id,
        );
    }

    public function testUpdateProjectToolSetsObjectiveAndStatus(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);

        $tool = new UpdateNervousSystemProjectTool()->withContext($app, $company, $user);
        $result = $tool(
            (int) $project->id,
            objective: 'Ship the redesign by end of month.',
            status: 'done',
        );

        $this->assertSame('Ship the redesign by end of month.', $result['objective']);
        $this->assertSame('done', $result['status']);

        $fresh = Project::query()->where('id', $project->id)->firstOrFail();
        $this->assertSame('Ship the redesign by end of month.', $fresh->objective);
        $this->assertSame('done', $fresh->status);
    }

    public function testTaskMoveRollsUpProjectCompletion(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $plan = $this->planUnderProject($project, $app, $company, $user);
        /** @var Task $task */
        $task = $plan->tasks()->firstOrFail();

        $this->assertSame(0, (int) $project->refresh()->completion_pct);

        new UpdateTaskStatusAction(task: $task, newStatus: TaskStatusEnum::DONE)->execute();

        // 1/1 tasks done → plan 100 → project 100.
        $this->assertSame(100, (int) $project->refresh()->completion_pct);
    }

    public function testProjectManagerAgentExposesBoardTools(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);

        $pm = new ProjectManagerAgent();
        $pm->setConfiguration($agent, null, null, $user);

        $method = new ReflectionMethod($pm, 'tools');
        /** @var array<int, object> $tools */
        $tools = $method->invoke($pm);

        $names = array_map(fn (object $tool): string => (string) $tool->getName(), $tools);

        $this->assertContains('update_nervous_system_project', $names);
        $this->assertContains('create_nervous_system_plan', $names);
        $this->assertContains('add_nervous_system_task', $names);
        $this->assertContains('assign_nervous_system_task', $names);
        $this->assertContains('update_nervous_system_task_status', $names);
        $this->assertContains('delete_nervous_system_task', $names);
        $this->assertContains('update_nervous_system_plan', $names);
        $this->assertContains('delete_nervous_system_plan', $names);
    }

    public function testUpdatePlanToolCompletesPlan(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $plan = $this->planUnderProject($project, $app, $company, $user);

        $tool = new UpdateNervousSystemPlanTool()->withContext($app, $company, $user);
        $result = $tool((int) $plan->id, status: 'done');

        $this->assertSame('done', $result['status']);
        $this->assertSame('done', Plan::query()->where('id', $plan->id)->value('status'));
    }

    public function testDeletePlanToolRemovesPlanAndCascades(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $plan = $this->planUnderProject($project, $app, $company, $user);
        /** @var Task $task */
        $task = $plan->tasks()->firstOrFail();

        $tool = new DeleteNervousSystemPlanTool()->withContext($app, $company, $user);
        $result = $tool((int) $plan->id);

        $this->assertTrue($result['deleted']);
        $this->assertSame(1, (int) Plan::query()->withTrashed()->where('id', $plan->id)->value('is_deleted'));
        $this->assertSame(1, (int) Task::query()->withTrashed()->where('id', $task->id)->value('is_deleted'));
    }

    public function testCreatePlanToolIsIdempotent(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);

        $tool = new CreateNervousSystemPlanTool()->withContext($app, $company, $user);
        $first = $tool((int) $project->id, 'Same title plan');
        $second = $tool((int) $project->id, 'Same title plan');

        $this->assertSame((int) $first['plan_id'], (int) $second['plan_id']);
        $this->assertTrue($second['reused'] ?? false);
    }

    public function testToolReturnsErrorForUnknownIdInsteadOfThrowing(): void
    {
        [$app, $company, $user] = $this->context();

        $tool = new AssignNervousSystemTaskTool()->withContext($app, $company, $user);
        $result = $tool(999999999, 888888888);

        $this->assertArrayHasKey('error', $result);
    }

    public function testDeleteTaskToolRemovesTaskAndRollsUp(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $plan = $this->planUnderProject($project, $app, $company, $user);
        /** @var Task $task */
        $task = $plan->tasks()->firstOrFail();

        $tool = new DeleteNervousSystemTaskTool()->withContext($app, $company, $user);
        $result = $tool((int) $task->id);

        $this->assertTrue($result['deleted']);
        $this->assertSame(
            1,
            (int) Task::query()->withTrashed()->where('id', $task->id)->value('is_deleted'),
        );
    }
}
