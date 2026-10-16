<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\ProjectManagement\ProjectManagerAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\ListAssignedWorkTool;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Actions\AddProjectMemberAction;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Enums\ProjectMemberRoleEnum;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Services\ProjectContextService;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class ListAssignedWorkToolTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'workflow', 'ecosystem'];

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
        $type = AgentType::factory()->create([
            'apps_id' => $app->getId(),
            'handler' => ProjectManagerAgent::class,
        ]);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId(), 'agent_type_id' => $type->id, 'is_active' => true]);
    }

    private function makeProject(Apps $app, Companies $company, Users $user, string $title): Project
    {
        return new CreateProjectAction(
            ProjectData::from($app, $user, $company, ['title' => $title, 'agent_id' => $this->makeAgent($app, $company, $user)->id]),
        )->execute();
    }

    /**
     * @param array<int, string> $taskTitles
     */
    private function planUnderProject(
        Project $project,
        Apps $app,
        Companies $company,
        Users $user,
        string $title,
        array $taskTitles = ['task one'],
        PlanStatusEnum $status = PlanStatusEnum::ACTIVE,
    ): Plan {
        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: $title,
                planType: 'project_work',
                user: $user,
                status: $status,
            ),
            tasks: array_map(
                static fn (string $taskTitle, int $i): TaskData => new TaskData(plan: null, title: $taskTitle, sequence: $i),
                $taskTitles,
                array_keys($taskTitles),
            ),
        )->execute();

        $plan->project_id = $project->id;
        $plan->saveQuietly();

        return $plan;
    }

    private function humanMemberOf(Project $project, Companies $company): Users
    {
        $human = Users::factory()->create();
        new AddProjectMemberAction(
            project: $project,
            role: ProjectMemberRoleEnum::CONTRIBUTOR,
            user: $human,
        )->execute();

        // The tool resolves a human through company membership, which the member action does not create.
        $company->associateUser($human, true, $company->defaultBranch);

        return $human;
    }

    public function testListsPlansAssignedToAHumanAcrossProjects(): void
    {
        Bus::fake();
        [$app, $company, $user] = $this->context();

        $alpha = $this->makeProject($app, $company, $user, 'Alpha');
        $beta = $this->makeProject($app, $company, $user, 'Beta');
        $human = $this->humanMemberOf($alpha, $company);

        $mine = $this->planUnderProject($alpha, $app, $company, $user, 'Tags on variants', ['add tags field', 'wire the UI']);
        $mine->assigned_users_id = $human->getId();
        $mine->saveQuietly();

        $mineElsewhere = $this->planUnderProject($beta, $app, $company, $user, 'Beta cleanup');
        $mineElsewhere->assigned_users_id = $human->getId();
        $mineElsewhere->saveQuietly();

        $notMine = $this->planUnderProject($alpha, $app, $company, $user, 'Backend sync');

        $result = new ListAssignedWorkTool()->withContext($app, $company, $user)->__invoke(users_id: $human->getId());

        $this->assertSame('success', $result['status']);
        $this->assertSame('user', $result['member']['type']);
        $this->assertSame($human->getId(), $result['member']['users_id']);

        $planIds = collect($result['plans'])->pluck('plan_id')->all();
        $this->assertContains($mine->getId(), $planIds);
        $this->assertContains($mineElsewhere->getId(), $planIds);
        $this->assertNotContains($notMine->getId(), $planIds);

        $tags = collect($result['plans'])->firstWhere('plan_id', $mine->getId());
        $this->assertSame($alpha->getId(), $tags['project_id']);
        $this->assertSame('member', $tags['owned_by']);
        $this->assertCount(2, $tags['tasks']);
        $this->assertSame(3, $result['total_tasks']);

        $scoped = new ListAssignedWorkTool()->withContext($app, $company, $user)->__invoke(
            users_id: $human->getId(),
            project_id: $beta->getId(),
        );
        $this->assertSame([$mineElsewhere->getId()], collect($scoped['plans'])->pluck('plan_id')->all());
    }

    public function testListsAnAgentsOwnPlansAndTheTasksItHoldsOnOtherPlans(): void
    {
        Bus::fake();
        [$app, $company, $user] = $this->context();

        $project = $this->makeProject($app, $company, $user, 'Agent work');
        $worker = $this->makeAgent($app, $company, $user);

        $owned = $this->planUnderProject($project, $app, $company, $user, 'Owned plan', ['a', 'b']);
        $owned->agent_id = $worker->getId();
        $owned->saveQuietly();

        $shared = $this->planUnderProject($project, $app, $company, $user, 'Shared plan', ['theirs', 'mine']);
        $heldTask = $shared->tasks->firstWhere('title', 'mine');
        $heldTask->agent_id = $worker->getId();
        $heldTask->saveQuietly();

        $result = new ListAssignedWorkTool()->withContext($app, $company, $user)->__invoke(agent_id: $worker->getId());

        $this->assertSame('success', $result['status']);
        $this->assertSame('agent', $result['member']['type']);

        $byPlan = collect($result['plans'])->keyBy('plan_id');
        $this->assertCount(2, $byPlan[$owned->getId()]['tasks']);
        $this->assertSame('member', $byPlan[$owned->getId()]['owned_by']);

        $this->assertStringContainsString('someone else', $byPlan[$shared->getId()]['owned_by']);
        $this->assertSame([$heldTask->getId()], collect($byPlan[$shared->getId()]['tasks'])->pluck('task_id')->all());
    }

    public function testOpenWorkOnlyUnlessIncludeClosed(): void
    {
        Bus::fake();
        [$app, $company, $user] = $this->context();

        $project = $this->makeProject($app, $company, $user, 'Closed work');
        $human = $this->humanMemberOf($project, $company);

        $open = $this->planUnderProject($project, $app, $company, $user, 'Still open', ['todo', 'done already']);
        $open->assigned_users_id = $human->getId();
        $open->saveQuietly();

        $finished = $open->tasks->firstWhere('title', 'done already');
        $finished->status = TaskStatusEnum::DONE->value;
        $finished->saveQuietly();

        $done = $this->planUnderProject($project, $app, $company, $user, 'Shipped', ['x'], PlanStatusEnum::DONE);
        $done->assigned_users_id = $human->getId();
        $done->saveQuietly();

        $tool = new ListAssignedWorkTool()->withContext($app, $company, $user);

        $openOnly = $tool(users_id: $human->getId());
        $this->assertSame([$open->getId()], collect($openOnly['plans'])->pluck('plan_id')->all());
        $this->assertSame(['todo'], collect($openOnly['plans'][0]['tasks'])->pluck('title')->all());

        $all = $tool(users_id: $human->getId(), include_closed: true);
        $planIds = collect($all['plans'])->pluck('plan_id')->all();
        $this->assertContains($done->getId(), $planIds);
        $this->assertCount(2, collect($all['plans'])->firstWhere('plan_id', $open->getId())['tasks']);
    }

    public function testTasksWaitingOnThePersonAreReportedSeparately(): void
    {
        Bus::fake();
        [$app, $company, $user] = $this->context();

        $project = $this->makeProject($app, $company, $user, 'Waiting');
        $human = $this->humanMemberOf($project, $company);

        $plan = $this->planUnderProject($project, $app, $company, $user, 'Needs an answer', ['ask Audry']);
        $task = $plan->tasks->first();
        $task->status = TaskStatusEnum::WAITING->value;
        $task->waiting_on_users_id = $human->getId();
        $task->saveQuietly();

        $result = new ListAssignedWorkTool()->withContext($app, $company, $user)->__invoke(users_id: $human->getId());

        $this->assertSame([], $result['plans']);
        $this->assertSame([$task->getId()], collect($result['tasks_waiting_on_them'])->pluck('task_id')->all());
        $this->assertSame($plan->getId(), $result['tasks_waiting_on_them'][0]['plan_id']);
    }

    public function testNothingAssignedSaysSoAndTellsTheModelNotToRetry(): void
    {
        Bus::fake();
        [$app, $company, $user] = $this->context();

        $project = $this->makeProject($app, $company, $user, 'Idle');
        $human = $this->humanMemberOf($project, $company);

        $result = new ListAssignedWorkTool()->withContext($app, $company, $user)->__invoke(users_id: $human->getId());

        $this->assertSame('success', $result['status']);
        $this->assertSame(0, $result['total_plans']);
        $this->assertStringContainsString('Nothing is assigned', $result['note']);
        $this->assertStringContainsString('do not retry', $result['note']);
    }

    public function testRejectsBothOrNeitherMemberAndUnknownIds(): void
    {
        [$app, $company, $user] = $this->context();
        $tool = new ListAssignedWorkTool()->withContext($app, $company, $user);

        $neither = $tool();
        $this->assertSame('error', $neither['status']);
        $this->assertStringContainsString('exactly one', $neither['message']);

        $both = $tool(users_id: $user->getId(), agent_id: 1);
        $this->assertSame('error', $both['status']);

        $stranger = Users::factory()->create();
        $unknownUser = $tool(users_id: $stranger->getId());
        $this->assertSame('error', $unknownUser['status']);
        $this->assertStringContainsString('not a member of this company', $unknownUser['message']);

        $unknownAgent = $tool(agent_id: 999999999);
        $this->assertSame('error', $unknownAgent['status']);
        $this->assertStringContainsString('was not found', $unknownAgent['message']);
    }

    public function testContextBundleExposesThePlanOwner(): void
    {
        Bus::fake();
        [$app, $company, $user] = $this->context();

        $project = $this->makeProject($app, $company, $user, 'Bundle');
        $human = $this->humanMemberOf($project, $company);

        $plan = $this->planUnderProject($project, $app, $company, $user, 'Owned by a person');
        $plan->assigned_users_id = $human->getId();
        $plan->saveQuietly();

        $bundle = new ProjectContextService()->buildContextBundle($project->refresh(), historyLimit: 1)->toArray();
        $row = collect($bundle['plans'])->firstWhere('plan_id', $plan->getId());

        $this->assertSame($human->getId(), $row['assigned_users_id']);
        $this->assertNull($row['agent_id']);
    }
}
