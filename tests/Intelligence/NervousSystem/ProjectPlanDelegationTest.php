<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\ProjectManagement\ProjectManagerAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\AssignNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\CommentOnNervousSystemPlanTool;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Jobs\WakeWorkerForPlanJob;
use Kanvas\NervousSystem\Project\Models\Project;
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
        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId(), 'is_active' => $active]);
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
        Bus::fake([WakeWorkerForPlanJob::class]);

        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $plan = $this->planUnderProject($project, $app, $company, $user);
        $worker = $this->makeAgent($app, $company, $user);

        $tool = new AssignNervousSystemPlanTool()->withContext($app, $company, $user);
        $result = $tool((int) $plan->id, (int) $worker->id);

        $this->assertSame((int) $worker->id, (int) $result['agent_id']);
        $this->assertSame((int) $worker->id, (int) Plan::query()->where('id', $plan->id)->value('agent_id'));

        Bus::assertDispatched(
            WakeWorkerForPlanJob::class,
            fn (WakeWorkerForPlanJob $job): bool => (int) $job->plan->getId() === (int) $plan->id,
        );
    }

    public function testAssignPlanReturnsErrorForUnknownIds(): void
    {
        [$app, $company, $user] = $this->context();
        $tool = new AssignNervousSystemPlanTool()->withContext($app, $company, $user);

        $this->assertArrayHasKey('error', $tool(999999999, 888888888));
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
