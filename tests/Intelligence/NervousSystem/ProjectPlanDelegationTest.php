<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\StateEnums;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\ProjectManagement\ProjectManagerAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\AssignNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\CommentOnNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\FindAndAddNervousSystemMemberTool;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\WakeAgentForPlanJob;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Actions\AddProjectMemberAction;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Enums\ProjectMemberRoleEnum;
use Kanvas\NervousSystem\Project\Jobs\NotifyProjectOwnerOfBlockedPlanJob;
use Kanvas\NervousSystem\Project\Jobs\WakeWorkerForPlanJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Models\ProjectMember;
use Kanvas\Users\Models\Users;
use ReflectionMethod;
use Tests\TestCase;

class ProjectPlanDelegationTest extends TestCase
{
    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'workflow'];

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

    private function makeAgent(Apps $app, Companies $company, Users $user, bool $active = true): Agent
    {
        // Executor-capable agents run on a BaseKanvasAgent handler (what canExecuteBoardWork checks).
        $type = AgentType::factory()->create([
            'apps_id' => $app->getId(),
            'handler' => ProjectManagerAgent::class,
        ]);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId(), 'agent_type_id' => $type->id, 'is_active' => $active]);
    }

    private function makeProject(Apps $app, Companies $company, Users $user): Project
    {
        return new CreateProjectAction(
            ProjectData::from(
                $app,
                $user,
                $company,
                ['title' => 'Delegation', 'agent_id' => $this->makeAgent($app, $company, $user)->id],
            ),
        )->execute();
    }

    private function planUnderProject(Project $project, Apps $app, Companies $company, Users $user): Plan
    {
        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Work stream',
                planType: 'project_work',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
            ),
        )->execute();

        $plan->project_id = $project->id;
        $plan->saveQuietly();

        return $plan;
    }

    public function testAssignPlanSetsOwnerAndWakesWorker(): void
    {
        Bus::fake([WakeAgentForPlanJob::class]);

        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $plan = $this->planUnderProject($project, $app, $company, $user);
        $worker = $this->makeAgent($app, $company, $user);

        $tool = new AssignNervousSystemPlanTool()->withContext($app, $company, $user);
        $result = $tool((int) $plan->id, (int) $worker->id);

        $this->assertSame((int) $worker->id, (int) $result['agent_id']);
        $this->assertSame((int) $worker->id, (int) Plan::query()->where('id', $plan->id)->value('agent_id'));

        // Assignment broadcasts ASSIGNED and the wake listener starts the continuation loop, so the
        // work runs through the task band where the CODE marks each task done and records what it
        // produced. The old hand-dispatched path left both to the agent, and an agent that answered
        // the question but skipped the status write failed silently — no error, task stuck pending.
        Bus::assertDispatched(
            WakeAgentForPlanJob::class,
            fn (WakeAgentForPlanJob $job): bool => (int) $job->plan->getId() === (int) $plan->id,
        );
    }

    public function testAssignPlanToNonExecutorAgentRecordsButDoesNotAutoRun(): void
    {
        Bus::fake([WakeAgentForPlanJob::class]);

        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $plan = $this->planUnderProject($project, $app, $company, $user);

        // A plain factory agent has no BaseKanvasAgent handler → can't board-execute. Still assignable.
        $nonExecutor = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId(), 'is_active' => true]);

        $tool = new AssignNervousSystemPlanTool()->withContext($app, $company, $user);
        $result = $tool((int) $plan->id, (int) $nonExecutor->id);

        $this->assertFalse($result['auto_run']);
        $this->assertSame((int) $nonExecutor->id, (int) Plan::query()->where('id', $plan->id)->value('agent_id'));
        Bus::assertNotDispatched(WakeAgentForPlanJob::class);
    }

    public function testAssignPlanToHumanRecordsOwnerWithoutAutoRun(): void
    {
        Bus::fake([WakeAgentForPlanJob::class]);

        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $plan = $this->planUnderProject($project, $app, $company, $user);

        $human = Users::factory()->create();
        // A plan can only be assigned to a HUMAN member of its project — register the human first.
        new AddProjectMemberAction($project, ProjectMemberRoleEnum::CONTRIBUTOR, user: $human)->execute();

        $tool = new AssignNervousSystemPlanTool()->withContext($app, $company, $user);
        $result = $tool((int) $plan->id, null, (int) $human->id);

        $this->assertSame('user', $result['assignee_type']);
        $this->assertFalse($result['auto_run']);
        $fresh = Plan::query()->where('id', $plan->id)->first();
        $this->assertSame((int) $human->id, (int) $fresh->assigned_users_id);
        $this->assertNull($fresh->agent_id);
        Bus::assertNotDispatched(WakeAgentForPlanJob::class);
    }

    public function testAssignPlanReturnsErrorForUnknownIds(): void
    {
        [$app, $company, $user] = $this->context();
        $tool = new AssignNervousSystemPlanTool()->withContext($app, $company, $user);

        $this->assertArrayHasKey('error', $tool(999999999, 888888888));
    }

    public function testBlockingAProjectPlanNotifiesTheOwner(): void
    {
        Bus::fake([NotifyProjectOwnerOfBlockedPlanJob::class]);

        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $plan = $this->planUnderProject($project, $app, $company, $user);

        $plan->status = PlanStatusEnum::BLOCKED->value;
        $plan->save();

        Bus::assertDispatched(
            NotifyProjectOwnerOfBlockedPlanJob::class,
            fn (NotifyProjectOwnerOfBlockedPlanJob $job): bool => (int) $job->plan->id === (int) $plan->id,
        );
    }

    public function testBlockedAlertIsThrottledPerPlan(): void
    {
        Notification::fake();

        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $plan = $this->planUnderProject($project, $app, $company, $user);
        $plan->status = PlanStatusEnum::BLOCKED->value;
        $plan->saveQuietly();

        $channel = $project->channels()->first();
        $before = (int) ($channel?->messages()->count() ?? 0);

        // Two runs (e.g. it re-blocked) within the window → the owner is alerted only ONCE.
        new NotifyProjectOwnerOfBlockedPlanJob($plan)->handle();
        new NotifyProjectOwnerOfBlockedPlanJob($plan)->handle();

        $after = (int) ($channel?->messages()->count() ?? 0);
        $this->assertSame(1, $after - $before);
    }

    public function testNonBlockedStatusChangeDoesNotNotifyOwner(): void
    {
        Bus::fake([NotifyProjectOwnerOfBlockedPlanJob::class]);

        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $plan = $this->planUnderProject($project, $app, $company, $user);

        $plan->status = PlanStatusEnum::DONE->value;
        $plan->save();

        Bus::assertNotDispatched(NotifyProjectOwnerOfBlockedPlanJob::class);
    }

    public function testCommentOnPlanToolPosts(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $plan = $this->planUnderProject($project, $app, $company, $user);

        $tool = new CommentOnNervousSystemPlanTool()->withContext($app, $company, $user);
        $result = $tool((int) $plan->id, 'Started the work, homepage first.');

        $this->assertTrue($result['posted'] ?? false);
        $this->assertSame((int) $plan->id, (int) $result['plan_id']);
    }

    public function testFindAndAddMemberByNameAddsExecutorAgent(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);

        $type = AgentType::factory()->create([
            'apps_id' => $app->getId(),
            'handler' => ProjectManagerAgent::class,
        ]);
        $named = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'user_id' => $user->getId(),
                'agent_type_id' => $type->id,
                'is_active' => true,
                'name' => 'Roberlina Builder',
            ]);

        $tool = new FindAndAddNervousSystemMemberTool()->withContext($app, $company, $user);
        $result = $tool((int) $project->id, 'Roberlina');

        $this->assertTrue($result['found']);
        $this->assertSame((int) $named->id, (int) $result['agent_id']);
        $this->assertTrue($result['can_execute']);
        $this->assertSame(
            1,
            (int) ProjectMember::query()->where('project_id', $project->id)->where('agent_id', $named->id)->count(),
        );
    }

    public function testFindAndAddMemberReturnsNotFoundForUnknownName(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);

        $tool = new FindAndAddNervousSystemMemberTool()->withContext($app, $company, $user);
        $result = $tool((int) $project->id, 'Zxqwtnobody12345');

        $this->assertFalse($result['found']);
    }

    public function testFindMemberIsScopedToTheGroupNotGlobal(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);

        // A user who belongs to a DIFFERENT company must NEVER be found — a global search would
        // cross tenants and add the wrong person into the project.
        $otherCompany = Companies::factory()->create();
        $outsider = Users::factory()->create(['firstname' => 'Mariazzzoutsider']);
        $otherCompany->associateUserApp($outsider, $app, StateEnums::YES->getValue());

        $tool = new FindAndAddNervousSystemMemberTool()->withContext($app, $company, $user);
        $result = $tool((int) $project->id, 'Mariazzzoutsider');

        $this->assertFalse($result['found']);
    }

    public function testPmExposesAssignPlanTool(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);

        $pm = new ProjectManagerAgent();
        $pm->setConfiguration($agent, null, null, $user);

        $method = new ReflectionMethod($pm, 'tools');
        /** @var array<int, object> $tools */
        $tools = $method->invoke($pm);
        $names = array_map(fn (object $t): string => (string) $t->getName(), $tools);

        $this->assertContains('assign_nervous_system_plan', $names);
        $this->assertContains('find_and_add_nervous_system_member', $names);
    }

    public function testPlanIsANamedAgentEntity(): void
    {
        // Regression: a Plan-scoped agent run calls getName() on the entity like it does for a
        // Lead/People. Without it, CRM/HR agents woken for a plan fatal on Plan::getName().
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $plan = $this->planUnderProject($project, $app, $company, $user);

        $this->assertSame('Work stream', $plan->getName());
    }

    public function testWakeWorkerSkipsInactiveAgent(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $plan = $this->planUnderProject($project, $app, $company, $user);

        $inactive = $this->makeAgent($app, $company, $user, active: false);
        $plan->agent_id = $inactive->id;
        $plan->saveQuietly();

        // Inactive agent → the job returns before touching the chat kernel (no exception, no run).
        new WakeWorkerForPlanJob($plan)->handle();

        $this->assertSame(0, (int) $plan->refresh()->completion_pct);
    }
}
