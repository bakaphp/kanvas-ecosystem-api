<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\ProjectManagement\ProjectManagerAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\AssignNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\AssignNervousSystemTaskTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\CommentOnNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\CreateNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\DeleteNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\DeleteNervousSystemTaskTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\HireAgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\UpdateNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\UpdateNervousSystemProjectTool;
use Kanvas\NervousSystem\Capability\Enums\AgentAbilityEnum;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\Actions\PostPlanActivityMessageAction;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Plan\Notifications\PlanProgressNotification;
use Kanvas\NervousSystem\Project\Actions\AddProjectMemberAction;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Enums\ProjectMemberRoleEnum;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForTaskJob;
use Kanvas\NervousSystem\Project\Jobs\WakeWorkerForPlanJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use ReflectionMethod;
use ReflectionProperty;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\Stubs\Intelligence\CapturingProjectManagerAgentStub;
use Tests\TestCase;

class ProjectPmToolsTest extends TestCase
{
    // The property below is inert without this trait — it was declared alone, so nothing this test
    // wrote was ever rolled back and the rows leaked into every test that ran after it.
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
        // Executor-capable agents run on a BaseKanvasAgent handler (what canExecuteBoardWork checks).
        $type = AgentType::factory()->create([
            'apps_id' => $app->getId(),
            'handler' => ProjectManagerAgent::class,
        ]);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId(), 'agent_type_id' => $type->id, 'is_active' => true]);
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

    private function projectFor(Agent $agent, Apps $app, Companies $company, Users $user, string $title): Project
    {
        return new CreateProjectAction(
            ProjectData::from($app, $user, $company, ['title' => $title, 'agent_id' => $agent->id]),
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

    public function testAssignRefusesAgentThatAlreadyBlockedButAllowsAnother(): void
    {
        Bus::fake([WakeWorkerForPlanJob::class]);
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $blocker = $project->pmAgent;

        $plan = $this->planUnderProject($project, $app, $company, $user);
        $plan->agent_id = $blocker->getId();
        $plan->status = PlanStatusEnum::BLOCKED->value;
        $plan->save(); // observer records the blocking agent as capability-declined

        $this->assertContains($blocker->getId(), $plan->refresh()->capability_declined_agent_ids ?? []);

        $tool = new AssignNervousSystemPlanTool()->withContext($app, $company, $user);

        // Re-handing the plan to the agent that already blocked it is refused.
        $refused = ($tool)(plan_id: $plan->id, agent_id: $blocker->getId());
        $this->assertArrayHasKey('error', $refused);
        $this->assertStringContainsString('already blocked', $refused['error']);

        // A different agent is accepted.
        $other = $this->makeAgent($app, $company, Users::factory()->create());
        $ok = ($tool)(plan_id: $plan->id, agent_id: $other->getId());
        $this->assertArrayNotHasKey('error', $ok);
        $this->assertSame($other->getId(), $plan->refresh()->agent_id);
    }

    public function testAssignPlanToHumanMemberSetsAssignedUserClearsAgentAndNotifies(): void
    {
        Notification::fake();

        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $plan = $this->planUnderProject($project, $app, $company, $user);

        // Pre-seed an agent owner so the human assignment must visibly CLEAR it (the exact state
        // that broke live: plan left owned by an agent while claiming a human owner).
        $plan->agent_id = $project->pmAgent->getId();
        $plan->saveQuietly();

        $human = Users::factory()->create();
        new AddProjectMemberAction(
            project: $project,
            role: ProjectMemberRoleEnum::CONTRIBUTOR,
            user: $human,
        )->execute();

        $tool = new AssignNervousSystemPlanTool()->withContext($app, $company, $user);
        $result = $tool(plan_id: (int) $plan->id, users_id: (int) $human->getId());

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('user', $result['assignee_type']);
        $this->assertSame((int) $human->getId(), (int) $result['users_id']);
        $this->assertTrue($result['notified'] ?? false);

        $fresh = $plan->refresh();
        $this->assertSame((int) $human->getId(), (int) $fresh->assigned_users_id);
        $this->assertNull($fresh->agent_id);

        // The assigned human is notified directly — not left to depend on the PM @mentioning them.
        Notification::assertSentTo(
            $human,
            PlanProgressNotification::class,
            fn (PlanProgressNotification $n): bool => ($n->getData()['metadata']['change_type'] ?? null) === 'assigned',
        );
    }

    public function testAssignPlanToHumanIsIdempotentAndDoesNotReNotify(): void
    {
        Notification::fake();

        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $plan = $this->planUnderProject($project, $app, $company, $user);

        $human = Users::factory()->create();
        new AddProjectMemberAction(
            project: $project,
            role: ProjectMemberRoleEnum::CONTRIBUTOR,
            user: $human,
        )->execute();

        $tool = new AssignNervousSystemPlanTool()->withContext($app, $company, $user);

        $first = $tool(plan_id: (int) $plan->id, users_id: (int) $human->getId());
        $this->assertTrue($first['notified'] ?? false);
        $this->assertArrayNotHasKey('already_assigned', $first);

        // A repeat of the identical call is a no-op: flagged already_assigned, NOT notified again — this
        // is what breaks the retry loop that trips ToolRunsExceededException.
        $second = $tool(plan_id: (int) $plan->id, users_id: (int) $human->getId());
        $this->assertTrue($second['already_assigned'] ?? false);
        $this->assertFalse($second['notified'] ?? true);

        // Exactly ONE notification total, despite two assign calls.
        Notification::assertSentToTimes($human, PlanProgressNotification::class, 1);
    }

    public function testAssignPlanToAgentIsIdempotentAndDoesNotReDispatch(): void
    {
        Bus::fake([WakeWorkerForPlanJob::class]);

        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $plan = $this->planUnderProject($project, $app, $company, $user);
        $executor = $project->pmAgent; // ProjectManagerAgent handler → canExecuteBoardWork() true

        $tool = new AssignNervousSystemPlanTool()->withContext($app, $company, $user);

        $first = $tool(plan_id: (int) $plan->id, agent_id: (int) $executor->getId());
        $this->assertArrayNotHasKey('already_assigned', $first);

        // Repeat: no-op, flagged already_assigned, and the worker is NOT dispatched a second time.
        $second = $tool(plan_id: (int) $plan->id, agent_id: (int) $executor->getId());
        $this->assertTrue($second['already_assigned'] ?? false);

        Bus::assertDispatchedTimes(WakeWorkerForPlanJob::class, 1);
    }

    public function testAssignPlanToNonMemberUserIsRefused(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $plan = $this->planUnderProject($project, $app, $company, $user);

        // A real user, but NOT a member of this project — must be rejected, not silently assigned
        // (the old bare global Users lookup would have accepted any id).
        $stranger = Users::factory()->create();

        $tool = new AssignNervousSystemPlanTool()->withContext($app, $company, $user);
        $result = $tool(plan_id: (int) $plan->id, users_id: (int) $stranger->getId());

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('type: user', $result['error']);
        $this->assertNull($plan->refresh()->assigned_users_id);
    }

    public function testWorkerSessionIsPerAgentSoReassignmentStartsFresh(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $agentA = $project->pmAgent;
        $agentB = $this->makeAgent($app, $company, Users::factory()->create());
        $plan = $this->planUnderProject($project, $app, $company, $user);

        $resolve = new ReflectionMethod(WakeWorkerForPlanJob::class, 'resolveSession');

        $plan->agent_id = $agentA->getId();
        $plan->saveQuietly();
        $sessionA = $resolve->invoke(new WakeWorkerForPlanJob($plan->refresh()));

        $plan->agent_id = $agentB->getId();
        $plan->saveQuietly();
        $sessionB = $resolve->invoke(new WakeWorkerForPlanJob($plan->refresh()));

        // Reassignment yields a NEW session keyed to the new agent — it doesn't inherit A's thread.
        $this->assertNotSame($sessionA->getId(), $sessionB->getId());
        $this->assertSame($agentA->getId(), (int) $sessionA->agents_id);
        $this->assertSame($agentB->getId(), (int) $sessionB->agents_id);
    }

    public function testWorkerWakeSkipsAlreadyBlockedPlanWhenNothingChanged(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $plan = $this->planUnderProject($project, $app, $company, $user);

        // The worker's OWN last note (posted by the provisioned $user — the worker's user).
        new PostPlanActivityMessageAction($plan, 'Blocked: no deploy tool.', author: $user)->execute();
        $plan->refresh();

        // The guard skips iff the last note on the plan is the worker's own — nothing changed since it
        // blocked. A different author having the last word (a human/PM reply) does NOT match → it runs.
        $someoneElse = Users::factory()->create();
        $this->assertTrue($this->lastNoteIsFrom($plan, $user));
        $this->assertFalse($this->lastNoteIsFrom($plan, $someoneElse));
    }

    private function lastNoteIsFrom(Plan $plan, Users $worker): bool
    {
        return new ReflectionMethod(WakeWorkerForPlanJob::class, 'lastNoteIsFrom')
            ->invoke(new WakeWorkerForPlanJob($plan), $worker);
    }

    public function testWorkerWakeContextIncludesStatusAndRecentNotes(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $plan = $this->planUnderProject($project, $app, $company, $user);
        $plan->status = PlanStatusEnum::BLOCKED->value;
        $plan->saveQuietly();

        new PostPlanActivityMessageAction($plan, 'I am blocked: I have no tool to deploy code.', author: $user)->execute();

        $plan->refresh();
        $message = new ReflectionMethod(WakeWorkerForPlanJob::class, 'buildMessage')
            ->invoke(new WakeWorkerForPlanJob($plan));

        // Worker sees the current status, its prior note, and the do-not-repeat guardrail.
        $this->assertStringContainsString('status=blocked', $message);
        $this->assertStringContainsString('Recent activity on this plan', $message);
        $this->assertStringContainsString('no tool to deploy code', $message);
        $this->assertStringContainsString('DO NOT REPEAT YOURSELF', $message);
    }

    public function testCommentToolSkipsDuplicateNoteOnBlockedPlan(): void
    {
        [$app, $company, $user] = $this->context();
        $project = $this->makeProject($app, $company, $user);
        $plan = $this->planUnderProject($project, $app, $company, $user);

        $tool = new CommentOnNervousSystemPlanTool()->withContext($app, $company, $user);
        $note = 'I am blocking this plan because it requires capabilities I do not have.';

        $first = ($tool)(plan_id: $plan->id, comment: $note);
        $this->assertTrue($first['posted'] ?? false);

        // Re-posting the identical note is skipped — deterministic, not left to the LLM.
        $second = ($tool)(plan_id: $plan->id, comment: $note);
        $this->assertFalse($second['posted'] ?? true);
        $this->assertSame('duplicate', $second['skipped'] ?? null);

        // Skips even when another note landed AFTER it (scans recent, not just the very last).
        ($tool)(plan_id: $plan->id, comment: 'Some other progress note.');
        $third = ($tool)(plan_id: $plan->id, comment: $note);
        $this->assertFalse($third['posted'] ?? true);

        // A genuinely-new note still posts.
        $fourth = ($tool)(plan_id: $plan->id, comment: $note . ' Update: still stuck.');
        $this->assertTrue($fourth['posted'] ?? false);

        // The plan channel holds exactly the 3 distinct notes — no duplicates.
        $contents = $plan->socialChannels()->first()?->messages()->get()
            ->map(fn (Message $m): string => is_array($m->message) ? trim((string) ($m->message['content'] ?? '')) : '')
            ->filter()
            ->values();
        $this->assertSame(3, $contents?->count());
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

    /**
     * A PM that can only hand work to teammates who already exist, through automation somebody else
     * already wired, cannot finish a job end to end — it stops at the edge of what is already set up.
     */
    public function testProjectManagerAgentCanStaffAndAutomateTheWork(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);

        $pm = new ProjectManagerAgent();
        $pm->setConfiguration($agent, null, null, $user);

        $names = array_map(
            fn (object $tool): string => (string) $tool->getName(),
            new ReflectionMethod($pm, 'tools')->invoke($pm)
        );

        foreach ([
            'hire_agent',
            'update_agent_instructions',
            'list_workflow_options',
            'list_company_workflows',
            'create_company_workflow',
            'update_company_workflow',
            'create_company_receiver',
            'create_email_route',
            'read_channel_window',
            'list_message_types',
            'create_message_type',
            // Lost once already: ProjectManagerAgent overrides SystemUserAgent::tools() without
            // calling parent, which drops these silently — a shorter list, no error.
            'schedule_reminder',
            'schedule_agent_task',
            'list_scheduled_actions',
            'cancel_scheduled_action',
        ] as $expected) {
            $this->assertContains($expected, $names, $expected . ' is missing from the PM toolset.');
        }
    }

    /**
     * An autonomous wake has no human in the turn — the wake jobs run the PM on its own user — so the
     * agent's own grant is the authorization. Making it an admin to get one capability would grant it
     * every other one on the way, hence a named ability.
     */
    public function testAGrantedAbilityAuthorizesHiringWithoutMakingTheAgentAnAdmin(): void
    {
        [$app, , $user] = $this->context();
        // Its own company: hiring is capped per company, and the shared one accumulates agents across
        // the suite until every hire here fails on the cap rather than on what it is testing.
        $company = Companies::factory()->create(['users_id' => $user->getId()]);
        $agentUser = Users::factory()->create();
        $agent = $this->makeAgent($app, $company, $agentUser);

        $tool = new HireAgentTool($agent)
            ->withContext($app, $company, $agentUser)
            ->forRequestingUser($agentUser);

        $this->assertFalse($agentUser->isAdmin(), 'The premise is an agent that is NOT an administrator.');

        $refused = $tool(
            name: 'Ungranted ' . fake()->unique()->lexify('?????'),
            role: 'Worker',
            instructions: 'Do the thing, or nothing.',
        );

        $this->assertFalse($refused['hired']);
        $this->assertStringContainsString('permission', $refused['message']);
        // The refusal has to tell the model what to do instead, or it retries or abandons the run.
        $this->assertStringContainsString('blocked', $refused['message']);

        Bouncer::scope()->to(RolesEnums::getScope($app));
        // Deliberately NOT refreshing Bouncer's cache: a grant made while a worker is running must
        // take effect without a restart, and the guard is what has to notice.
        Bouncer::allow($agentUser)->to(AgentAbilityEnum::HIRE_AGENT->value);

        $allowed = $tool(
            name: 'Granted ' . fake()->unique()->lexify('?????'),
            role: 'Worker',
            instructions: 'Do the thing, or nothing.',
        );

        $this->assertTrue($allowed['hired'], $allowed['message'] ?? '');
        $this->assertFalse(
            $agentUser->refresh()->isAdmin(),
            'The grant must not have made the agent an administrator.'
        );
    }

    /**
     * The PM's own user is usually an admin, so an admin-guarded tool that authorized against it
     * would hand the PM's rights to whoever is talking to the PM. On the @mention surface the turn's
     * actor IS the agent's own user, and only the conversation human is the real person.
     */
    public function testAdminGuardedPmToolsAuthorizeTheHumanNotThePmItself(): void
    {
        [$app, $company, $user] = $this->context();
        $agentUser = Users::factory()->create();
        $agent = $this->makeAgent($app, $company, $agentUser);

        $pm = new ProjectManagerAgent();
        $pm->setConfiguration($agent, null, null, $agentUser);

        $this->assertSame(
            $agentUser->getId(),
            $pm->requestingHuman()?->getId(),
            'With nobody identified the actor stands in, which is correct on a user-chat surface.'
        );

        $pm->setConversationHuman($user);

        $this->assertSame(
            $user->getId(),
            $pm->requestingHuman()?->getId(),
            'Once the human is known it must win over the agent\'s own user.'
        );

        $hire = array_values(array_filter(
            new ReflectionMethod($pm, 'tools')->invoke($pm),
            fn (object $tool): bool => (string) $tool->getName() === 'hire_agent'
        ));

        $this->assertCount(1, $hire);

        $requesting = new ReflectionProperty($hire[0], 'requestingUser');

        $this->assertSame(
            $user->getId(),
            $requesting->getValue($hire[0])?->getId(),
            'hire_agent must be bound to the conversation human, not the PM\'s own user.'
        );
    }

    public function testInstructionsGroundThePmInItsOwnProject(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);
        $project = new CreateProjectAction(
            ProjectData::from($app, $user, $company, ['title' => 'Grounding project', 'agent_id' => $agent->id]),
        )->execute();

        $pm = new ProjectManagerAgent();
        $pm->setConfiguration($agent, null, null, $user);

        $instructions = $pm->instructions();

        // The PM is grounded in its real project (resolved from agent_id) — no room to confabulate one.
        $this->assertStringContainsString('CURRENT PROJECT', $instructions);
        $this->assertStringContainsString('Grounding project', $instructions);
        $this->assertStringContainsString((string) $project->getId(), $instructions);
        $this->assertStringContainsString('NEVER invent', $instructions);
    }

    public function testInstructionsGroundOnTheProjectInScopeWhenThePmOwnsSeveral(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);
        $this->projectFor($agent, $app, $company, $user, 'First board');
        $second = $this->projectFor($agent, $app, $company, $user, 'Second board');

        $pm = new ProjectManagerAgent();
        $pm->setConfiguration($agent, $second, null, $user);

        $instructions = $pm->instructions();

        $this->assertStringContainsString('CURRENT PROJECT', $instructions);
        $this->assertStringContainsString('Second board', $instructions);
        $this->assertStringNotContainsString('First board', $instructions);
    }

    public function testInstructionsAskWhichProjectWhenSeveralAreOwnedAndNoneIsInScope(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);
        $first = $this->projectFor($agent, $app, $company, $user, 'Ambiguous board A');
        $second = $this->projectFor($agent, $app, $company, $user, 'Ambiguous board B');

        $pm = new ProjectManagerAgent();
        $pm->setConfiguration($agent, null, null, $user);

        $instructions = $pm->instructions();

        $this->assertStringContainsString('YOU MANAGE SEVERAL PROJECTS', $instructions);
        $this->assertStringContainsString('project_id ' . $first->getId(), $instructions);
        $this->assertStringContainsString('project_id ' . $second->getId(), $instructions);
        // No single project may be grounded as authoritative — that's what used to pick one at random.
        $this->assertStringNotContainsString('CURRENT PROJECT (authoritative', $instructions);
    }

    public function testWakeGroundsThePmInTheWokenProjectNotAnArbitraryOne(): void
    {
        [$app, $company, $user] = $this->context();

        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create([
                'provider' => 'neuron',
                'handler' => CapturingProjectManagerAgentStub::class,
            ]);

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'agent_type_id' => $agentType->getId(),
                'user_id' => $user->getId(),
                'is_active' => true,
            ]);

        $this->projectFor($agent, $app, $company, $user, 'Wake board A');
        $second = $this->projectFor($agent, $app, $company, $user, 'Wake board B');

        CapturingProjectManagerAgentStub::$lastInstructions = '';

        new WakeAgentForProjectJob(
            $second,
            WakeAgentForProjectJob::REASON_INGEST,
            'Client wants dark mode by Friday.',
        )->handle();

        $captured = CapturingProjectManagerAgentStub::$lastInstructions;

        $this->assertStringContainsString('Wake board B', $captured);
        $this->assertStringNotContainsString('Wake board A', $captured);
    }

    public function testInstructionsRefuseToInventAProjectWhenNoneIsBound(): void
    {
        [$app, $company, $user] = $this->context();
        // An agent that is not the PM of any project — the reply path must not let it fabricate one.
        $agent = $this->makeAgent($app, $company, $user);

        $pm = new ProjectManagerAgent();
        $pm->setConfiguration($agent, null, null, $user);

        $instructions = $pm->instructions();

        $this->assertStringContainsString('NO PROJECT LOADED', $instructions);
        $this->assertStringContainsString('NEVER invent', $instructions);
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
