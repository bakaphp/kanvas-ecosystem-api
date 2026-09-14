<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\ProjectManagement\ProjectManagerAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\MoveNervousSystemPlanTool;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\PushPlanToKanbanJob;
use Kanvas\NervousSystem\Plan\Jobs\WakeAgentForPlanJob;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Actions\AddProjectMemberAction;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Enums\ProjectMemberRoleEnum;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForTaskJob;
use Kanvas\NervousSystem\Project\Jobs\WakeWorkerForPlanJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class MoveNervousSystemPlanToolTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'workflow', 'ecosystem'];

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([
            WakeAgentForPlanJob::class,
            WakeAgentForProjectJob::class,
            WakeAgentForTaskJob::class,
            WakeWorkerForPlanJob::class,
            PushPlanToKanbanJob::class,
        ]);
        Notification::fake();
    }

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
            ProjectData::from(
                $app,
                $user,
                $company,
                ['title' => $title, 'agent_id' => $this->makeAgent($app, $company, $user)->id],
            ),
        )->execute();
    }

    private function planUnderProject(
        Project $project,
        Apps $app,
        Companies $company,
        Users $user,
        int $completionPct = 0,
    ): Plan {
        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Build the thing',
                planType: 'project_work',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
            ),
            tasks: [new TaskData(plan: null, title: 'task one', sequence: 0)],
        )->execute();

        $plan->project_id = $project->getId();
        $plan->completion_pct = $completionPct;
        $plan->saveQuietly();

        return $plan;
    }

    public function testMovesThePlanAndRollsUpBothProjects(): void
    {
        [$app, $company, $user] = $this->context();
        $source = $this->makeProject($app, $company, $user, 'Source board');
        $destination = $this->makeProject($app, $company, $user, 'Destination board');

        $moving = $this->planUnderProject($source, $app, $company, $user, completionPct: 100);
        $staying = $this->planUnderProject($source, $app, $company, $user, completionPct: 0);

        $source->recomputeCompletionPct();
        $this->assertSame(50, (int) $source->refresh()->completion_pct);

        $tool = new MoveNervousSystemPlanTool()->withContext($app, $company, $user);
        $result = $tool(plan_id: (int) $moving->getId(), project_id: (int) $destination->getId());

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame($destination->getId(), (int) $result['to_project_id']);
        $this->assertSame($source->getId(), (int) $result['from_project_id']);
        $this->assertSame($destination->getId(), (int) $moving->refresh()->project_id);
        $this->assertSame($source->getId(), (int) $staying->refresh()->project_id);

        // The source roll-up is the half a plain project_id write gets wrong: it still averaged a plan
        // the project no longer owns.
        $this->assertSame(0, (int) $source->refresh()->completion_pct);
        $this->assertSame(100, (int) $destination->refresh()->completion_pct);
    }

    public function testTasksTravelWithThePlan(): void
    {
        [$app, $company, $user] = $this->context();
        $source = $this->makeProject($app, $company, $user, 'Source board');
        $destination = $this->makeProject($app, $company, $user, 'Destination board');
        $plan = $this->planUnderProject($source, $app, $company, $user);

        $tool = new MoveNervousSystemPlanTool()->withContext($app, $company, $user);
        $tool(plan_id: (int) $plan->getId(), project_id: (int) $destination->getId());

        $this->assertCount(1, $plan->refresh()->tasks);
    }

    public function testSubPlansFollowTheirParentSoTheTreeIsNeverSplit(): void
    {
        [$app, $company, $user] = $this->context();
        $source = $this->makeProject($app, $company, $user, 'Source board');
        $destination = $this->makeProject($app, $company, $user, 'Destination board');

        $parent = $this->planUnderProject($source, $app, $company, $user);
        $child = $this->planUnderProject($source, $app, $company, $user);
        $grandchild = $this->planUnderProject($source, $app, $company, $user);

        $child->parent_plan_id = $parent->getId();
        $child->saveQuietly();
        $grandchild->parent_plan_id = $child->getId();
        // An unfiled sub-plan is the NULL case `project_id != x` silently skips.
        $grandchild->project_id = null;
        $grandchild->saveQuietly();

        $tool = new MoveNervousSystemPlanTool()->withContext($app, $company, $user);
        $result = $tool(plan_id: (int) $parent->getId(), project_id: (int) $destination->getId());

        $this->assertSame(2, (int) $result['sub_plans_moved']);
        $this->assertSame($destination->getId(), (int) $child->refresh()->project_id);
        $this->assertSame($destination->getId(), (int) $grandchild->refresh()->project_id);
    }

    public function testDropsAnAgentOwnerWhoIsNotAMemberOfTheDestination(): void
    {
        [$app, $company, $user] = $this->context();
        $source = $this->makeProject($app, $company, $user, 'Source board');
        $destination = $this->makeProject($app, $company, $user, 'Destination board');

        $plan = $this->planUnderProject($source, $app, $company, $user);
        $plan->agent_id = $source->pmAgent->getId();
        $plan->saveQuietly();

        $tool = new MoveNervousSystemPlanTool()->withContext($app, $company, $user);
        $result = $tool(plan_id: (int) $plan->getId(), project_id: (int) $destination->getId());

        $this->assertTrue($result['unassigned']);
        $this->assertNull($plan->refresh()->agent_id);
        $this->assertStringContainsString('UNOWNED', (string) $result['message']);
    }

    public function testKeepsAnAgentOwnerWhoIsAMemberOfTheDestination(): void
    {
        [$app, $company, $user] = $this->context();
        $source = $this->makeProject($app, $company, $user, 'Source board');
        $destination = $this->makeProject($app, $company, $user, 'Destination board');
        $owner = $source->pmAgent;

        new AddProjectMemberAction(
            project: $destination,
            role: ProjectMemberRoleEnum::CONTRIBUTOR,
            agent: $owner,
        )->execute();

        $plan = $this->planUnderProject($source, $app, $company, $user);
        $plan->agent_id = $owner->getId();
        $plan->saveQuietly();

        $tool = new MoveNervousSystemPlanTool()->withContext($app, $company, $user);
        $result = $tool(plan_id: (int) $plan->getId(), project_id: (int) $destination->getId());

        $this->assertFalse($result['unassigned']);
        $this->assertSame($owner->getId(), (int) $plan->refresh()->agent_id);
    }

    public function testDropsAHumanOwnerWhoIsNotAMemberOfTheDestination(): void
    {
        [$app, $company, $user] = $this->context();
        $source = $this->makeProject($app, $company, $user, 'Source board');
        $destination = $this->makeProject($app, $company, $user, 'Destination board');

        new AddProjectMemberAction(
            project: $source,
            role: ProjectMemberRoleEnum::CONTRIBUTOR,
            user: $user,
        )->execute();

        $plan = $this->planUnderProject($source, $app, $company, $user);
        $plan->assigned_users_id = $user->getId();
        $plan->saveQuietly();

        $tool = new MoveNervousSystemPlanTool()->withContext($app, $company, $user);
        $result = $tool(plan_id: (int) $plan->getId(), project_id: (int) $destination->getId());

        $this->assertTrue($result['unassigned']);
        $this->assertNull($plan->refresh()->assigned_users_id);
    }

    public function testMovingIntoTheSameProjectIsANoop(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user, 'Only board');
        $plan = $this->planUnderProject($project, $app, $company, $user);

        $tool = new MoveNervousSystemPlanTool()->withContext($app, $company, $user);
        $result = $tool(plan_id: (int) $plan->getId(), project_id: (int) $project->getId());

        $this->assertSame('noop', $result['outcome']);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function testUnknownProjectReturnsAStructuredError(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user, 'Only board');
        $plan = $this->planUnderProject($project, $app, $company, $user);

        $tool = new MoveNervousSystemPlanTool()->withContext($app, $company, $user);
        $result = $tool(plan_id: (int) $plan->getId(), project_id: 99999999);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame($project->getId(), (int) $plan->refresh()->project_id);
    }
}
