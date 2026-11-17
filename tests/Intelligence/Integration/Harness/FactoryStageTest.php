<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\CodingChatSettingEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\FactoryProjectSettingEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\AgentRuntime\Harness\Support\TaskDirectives;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\ProjectManagement\ProjectManagerAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Harness\AnswerHarnessCodingPermissionTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\StartAppKickoffTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\StartFactoryReleaseTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\StartFactoryTicketTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\UpdateNervousSystemTaskStatusTool;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\WakeAgentForPlanJob;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForTaskJob;
use Kanvas\NervousSystem\Project\Models\Project;
use ReflectionMethod;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * The factory's stages written in code. An orchestrator typing its own tasks leaves out the `Repo:`
 * lines, and the app work runs in the default checkout or not at all.
 */
final class FactoryStageTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    private Agent $orchestrator;

    public function testAKickoffRunsBothStepsOnTheMacInOrder(): void
    {
        Queue::fake();
        [$project, $builder] = $this->factoryProject();

        $result = $this->kickoff()(
            $project->getId(),
            'example-app',
            'Example App',
            'com.example.app',
        );

        $this->assertTrue($result['success'], json_encode($result));
        [$create, $configure] = $this->stepsOf((int) $result['plan_id']);

        $this->assertSame([1, 2], [(int) $create->sequence, (int) $configure->sequence]);
        $this->assertTrue($create->agent?->is($builder) && $configure->agent?->is($builder));
        $this->assertSame(FactoryProjectSettingEnum::DEFAULT_TEMPLATE, TaskDirectives::repo($create->fullText()));
        $this->assertStringContainsString('New app: example-app', (string) $create->description);
        $this->assertSame('example-app', TaskDirectives::repo($configure->fullText()));
        $this->assertSame('kickoff/setup', TaskDirectives::branch($configure->fullText()));
        $this->assertStringContainsString('--bundle com.example.app', (string) $configure->description);
        Queue::assertPushed(WakeAgentForTaskJob::class, 2);
    }

    /** A kickoff started again, after an interrupted run, must not create the app twice. */
    public function testStartingTheSameKickoffAgainReturnsTheOneOnTheBoard(): void
    {
        Queue::fake();
        [$project] = $this->factoryProject();
        $kickoff = $this->kickoff();

        $first = $kickoff($project->getId(), 'example-app', 'Example App', 'com.example.app');
        $again = $kickoff($project->getId(), 'example-app', 'Example App', 'com.example.app');

        $this->assertTrue($again['reused']);
        $this->assertSame($first['plan_id'], $again['plan_id']);
        $this->assertSame(1, Plan::query()->where('project_id', $project->getId())->count());
        $this->assertStringContainsString('do not create them again', (string) $this->stepsOf((int) $first['plan_id'])[0]->description);
    }

    public function testATicketIsDesignedThenBuiltOnOneBranch(): void
    {
        Queue::fake();
        [$project, $builder, $designer] = $this->factoryProject();

        $result = $this->ticket()(
            $project->getId(),
            'example-app',
            3,
            'Record list screen',
            "As a user I see my items.\nQuery: Repository: kanvas-ecosystem-api serves `files`.",
        );

        [$design, $ship] = $this->stepsOf((int) $result['plan_id']);

        $this->assertTrue($design->agent?->is($designer));
        $this->assertTrue($ship->agent?->is($builder));

        foreach ([$design, $ship] as $step) {
            // The ticket's own "Repository:" must not contradict the task's line and block it.
            $this->assertSame('example-app', TaskDirectives::repo($step->fullText()));
            $this->assertSame('ticket/3-record-list-screen', TaskDirectives::branch($step->fullText()));
        }

        $this->assertStringContainsString('Commit only', (string) $design->description);
        // Both steps start from where earlier tickets landed, and the ticket lands there with no one to wait for.
        $this->assertStringContainsString('merge it into this branch', (string) $design->description);
        $this->assertStringContainsString('git push origin HEAD:factory/integration', (string) $ship->description);
        $this->assertStringContainsString('never open a pull request', (string) $ship->description);
        // The app's mandatory Argent QA run is skipped when the task does not name it.
        $this->assertStringContainsString('specs/qa/app-test.runbook.md', (string) $ship->description);
        // A ticket must not be reported done while a simulator cannot run it.
        $this->assertStringContainsString('BOTH the iOS simulator and the Android emulator', (string) $ship->description);
        $this->assertStringContainsString('reply BLOCKED', (string) $ship->description);
        // App data comes from Kanvas (messages, custom fields), never a new table or dummy data.
        $this->assertStringContainsString('Kanvas message with its own message type', (string) $ship->description);
        $this->assertStringNotContainsString('app-test.runbook', (string) $design->description);
    }

    /** The stage's one pull request: the integration branch into develop, from a branch cut off develop. */
    public function testAReleaseOpensTheOnePullRequestIntoDevelop(): void
    {
        Queue::fake();
        [$project, $builder] = $this->factoryProject();
        $user = static::$cachedUser;
        $release = new StartFactoryReleaseTool()->withContext(
            app(Apps::class),
            $user->getCurrentCompany(),
            $user,
            $this->orchestrator,
        );

        $result = $release($project->getId(), 'example-app');
        $again = $release($project->getId(), 'example-app');

        $steps = $this->stepsOf((int) $result['plan_id']);
        $this->assertCount(1, $steps);
        $this->assertTrue($steps[0]->agent?->is($builder));
        $this->assertSame('example-app', TaskDirectives::repo($steps[0]->fullText()));
        $this->assertSame('release/' . now()->toDateString(), TaskDirectives::branch($steps[0]->fullText()));
        $this->assertStringContainsString('merge `origin/factory/integration`', (string) $steps[0]->description);
        $this->assertStringContainsString('changelog entry dated ' . now()->toDateString(), (string) $steps[0]->description);
        $this->assertStringContainsString('open the pull request into `develop`', (string) $steps[0]->description);
        $this->assertTrue($again['reused']);
    }

    /** A factory task sent back to pending must run again: a plan wake alone is ignored by a factory agent. */
    public function testAFactoryTaskSentBackToPendingRunsAgain(): void
    {
        Queue::fake();
        [$project] = $this->factoryProject();
        $result = $this->kickoff()($project->getId(), 'example-app', 'Example App', 'com.example.app');
        [$create] = $this->stepsOf((int) $result['plan_id']);
        $user = static::$cachedUser;
        $status = new UpdateNervousSystemTaskStatusTool()->withContext(app(Apps::class), $user->getCurrentCompany(), $user);

        $status($create->getId(), 'blocked', blocked_reason: 'The run was interrupted.');
        $status($create->getId(), 'pending', description: "Repo: kanvas-mobile-skeleton\nNew app: example-app\nAdd it to apps.txt.");

        Queue::assertPushed(
            WakeAgentForTaskJob::class,
            fn (WakeAgentForTaskJob $job): bool => $job->task->is($create),
        );
        $this->assertSame(2, Queue::pushed(WakeAgentForTaskJob::class, fn (WakeAgentForTaskJob $job): bool => $job->task->is($create))->count());
        Queue::assertNotPushed(
            WakeAgentForPlanJob::class,
            fn (WakeAgentForPlanJob $job): bool => $job->reason === WakeAgentForPlanJob::REASON_TASK_REOPENED,
        );
    }

    /**
     * A plan left blocked after its task was sent back gets no still-working updates, and the wake that
     * reports a finished stage skips a blocked plan.
     */
    public function testSendingTheBlockedWorkBackUnblocksThePlanUnlessAnotherStepIsStillBlocked(): void
    {
        Queue::fake();
        [$project] = $this->factoryProject();
        $result = $this->kickoff()($project->getId(), 'example-app', 'Example App', 'com.example.app');
        $plan = Plan::query()->findOrFail($result['plan_id']);
        [$create, $configure] = $this->stepsOf($plan->getId());
        $user = static::$cachedUser;
        $status = new UpdateNervousSystemTaskStatusTool()->withContext(app(Apps::class), $user->getCurrentCompany(), $user);

        $status($create->getId(), 'blocked', blocked_reason: 'The run was interrupted.');
        $status($configure->getId(), 'blocked', blocked_reason: 'Waiting on step 1.');
        $plan->update(['status' => 'blocked']);

        $status($create->getId(), 'pending');
        $this->assertSame('blocked', $plan->refresh()->status, 'step 2 is still blocked');

        $status($configure->getId(), 'pending');
        $this->assertSame('active', $plan->refresh()->status);
    }

    /** Only factory work: any other plan keeps the status its agents gave it. */
    public function testReopeningATaskOutsideTheFactoryLeavesItsPlanBlocked(): void
    {
        Queue::fake();
        $plan = $this->makePlan(['status' => 'blocked']);
        $task = $this->makeTask($plan, TaskStatusEnum::BLOCKED);
        $task->agent_id = $plan->agent_id;
        $task->saveOrFail();
        $user = static::$cachedUser;

        new UpdateNervousSystemTaskStatusTool()->withContext(app(Apps::class), $user->getCurrentCompany(), $user)(
            $task->getId(),
            'pending',
        );

        $this->assertSame('blocked', $plan->refresh()->status);
    }

    /** A company without a factory Mac keeps its project manager's toolset exactly as it was. */
    public function testOnlyACompanyWithAFactoryGivesItsProjectManagerTheStageTools(): void
    {
        $pm = $this->makeAgent();
        $pm->type->update(['handler' => ProjectManagerAgent::class]);

        $this->assertNotContains('start_app_kickoff', $this->toolNamesOf($pm->refresh()));
        $this->assertNotContains('answer_self_hosted_coding_permission', $this->toolNamesOf($pm));

        $this->factoryAgent();

        $tools = $this->toolNamesOf($pm);
        $this->assertContains('start_app_kickoff', $tools);
        $this->assertContains('start_factory_ticket', $tools);
        $this->assertContains('start_factory_release', $tools);
        $this->assertContains('answer_self_hosted_coding_permission', $tools);
        $this->assertContains('answer_self_hosted_coding_question', $tools);
    }

    /** A PM that read a pending ask as "blocked" marked a live run blocked, and the plan above it followed. */
    public function testATaskWhoseRunIsStillGoingKeepsItsStatus(): void
    {
        Queue::fake();
        [$project] = $this->factoryProject();
        $result = $this->kickoff()($project->getId(), 'example-app', 'Example App', 'com.example.app');
        [$create] = $this->stepsOf((int) $result['plan_id']);

        $run = new AgentTaskSession();
        $run->apps_id = $create->apps_id;
        $run->companies_id = $create->companies_id;
        $run->agent_id = $create->agent_id;
        $run->plan_id = $create->plan_id;
        $run->task_id = $create->getId();
        $run->harness = HarnessEnum::OPENCODE->value;
        $run->status = HarnessStatusEnum::AWAITING_PERMISSION->value;
        $run->saveOrFail();

        $user = static::$cachedUser;
        $status = new UpdateNervousSystemTaskStatusTool()->withContext(app(Apps::class), $user->getCurrentCompany(), $user);
        $before = $create->refresh()->status;

        $answer = $status($create->getId(), 'blocked', blocked_reason: 'Waiting on a permission.');

        $this->assertStringContainsString('still going', (string) ($answer['error'] ?? ''));
        $this->assertSame($before, $create->refresh()->status);
    }

    /** The orchestrator passes on a person's decision about a teammate's run; it never makes one. */
    public function testTheOrchestratorOnlyRelaysAPermission(): void
    {
        $tool = new AnswerHarnessCodingPermissionTool($this->makeAgent(), onlyRelays: true);

        $result = $tool(job_id: 1, permission_id: 'per_1', decision: 'once');

        $this->assertFalse($result['success']);
        $this->assertSame('denied', $result['outcome']);
    }

    /** The orchestrator's turn runs on a queue worker, where nobody is logged in. */
    public function testTheBuilderResolvesWithNobodyLoggedIn(): void
    {
        [$project, $builder] = $this->factoryProject();
        Auth::forgetUser();

        $this->assertTrue(FactoryProjectSettingEnum::builderFor($project->refresh())?->is($builder));
    }

    /** A ticket with nothing on screen has no design step to run. */
    public function testATicketWithNothingOnScreenGoesStraightToTheBuilder(): void
    {
        Queue::fake();
        [$project, $builder] = $this->factoryProject();

        $result = $this->ticket()(
            $project->getId(),
            'example-app',
            1,
            'domain contracts',
            'Define the typed interfaces for the app\'s items.',
            has_screens: false,
        );

        $steps = $this->stepsOf((int) $result['plan_id']);

        $this->assertCount(1, $steps);
        $this->assertTrue($steps[0]->agent?->is($builder));
        $this->assertSame('ticket/1-domain-contracts', TaskDirectives::branch($steps[0]->fullText()));
        $this->assertStringNotContainsString('screenshots', (string) $steps[0]->description);
    }

    public function testWithoutABuilderNothingIsStarted(): void
    {
        Queue::fake();
        [$project] = $this->factoryProject();
        $project->set(FactoryProjectSettingEnum::BUILDER_AGENT->value, 0);

        $result = $this->kickoff()($project->getId(), 'example-app', 'Example App', 'com.example.app');

        $this->assertFalse($result['success'] ?? false);
        $this->assertStringContainsString(FactoryProjectSettingEnum::BUILDER_AGENT->value, (string) $result['error']);
        $this->assertSame(0, Plan::query()->where('project_id', $project->getId())->count());
        Queue::assertNotPushed(WakeAgentForTaskJob::class);
    }

    public function testABadBundleIdIsCorrectableAndStartsNothing(): void
    {
        Queue::fake();
        [$project] = $this->factoryProject();

        $result = $this->kickoff()($project->getId(), 'example-app', 'Example App', 'not a bundle id');

        $this->assertStringContainsString('not a bundle id', (string) ($result['error'] ?? ''));
        $this->assertSame(0, Plan::query()->where('project_id', $project->getId())->count());
    }

    /**
     * @return array{0: Project, 1: Agent, 2: Agent}
     */
    private function factoryProject(): array
    {
        $builder = $this->factoryAgent();
        $designer = $this->factoryAgent();
        $user = static::$cachedUser;
        $this->orchestrator = $this->makeAgent();

        $project = new CreateProjectAction(
            ProjectData::from(
                app(Apps::class),
                $user,
                $user->getCurrentCompany(),
                ['title' => 'Mobile Factory', 'agent_id' => $this->orchestrator->getId()],
            ),
        )->execute();
        $project->set(FactoryProjectSettingEnum::BUILDER_AGENT->value, $builder->getId());
        $project->set(FactoryProjectSettingEnum::DESIGNER_AGENT->value, $designer->getId());

        return [$project, $builder, $designer];
    }

    private function factoryAgent(): Agent
    {
        $agent = $this->makeAgent();
        $agent->update(['is_active' => true]);
        $agent->set(CodingChatSettingEnum::MODE->value, true);

        return $agent;
    }

    private function kickoff(): StartAppKickoffTool
    {
        $user = static::$cachedUser;

        return new StartAppKickoffTool()->withContext(
            app(Apps::class),
            $user->getCurrentCompany(),
            $user,
            $this->orchestrator,
        );
    }

    private function ticket(): StartFactoryTicketTool
    {
        $user = static::$cachedUser;

        return new StartFactoryTicketTool()->withContext(
            app(Apps::class),
            $user->getCurrentCompany(),
            $user,
            $this->orchestrator,
        );
    }

    /**
     * @return list<string>
     */
    private function toolNamesOf(Agent $agent): array
    {
        $handler = new ProjectManagerAgent();
        $handler->setConfiguration($agent, user: static::$cachedUser);

        /** @var array<int, object> $tools */
        $tools = new ReflectionMethod($handler, 'tools')->invoke($handler);

        return array_values(array_map(fn (object $tool): string => (string) $tool->getName(), $tools));
    }

    /**
     * @return list<Task>
     */
    private function stepsOf(int $planId): array
    {
        return array_values(Task::query()->where('plan_id', $planId)->orderBy('sequence')->get()->all());
    }
}
