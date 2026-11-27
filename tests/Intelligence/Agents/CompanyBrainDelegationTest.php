<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\CreateNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\GrantAgentToolsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\HireAgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\ListAgentsTool;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Support\WorkerToolPolicy;
use Kanvas\NervousSystem\Project\Actions\EnsureCompanyOperationsProjectAction;
use Kanvas\Users\Models\Users;
use Tests\TestCase;
use Tests\Traits\MakesCompanyBrain;
use Tests\Traits\MakesEmployees;
use Tests\Traits\MakesPlans;

final class CompanyBrainDelegationTest extends TestCase
{
    use DatabaseTransactions;
    use MakesCompanyBrain;
    use MakesEmployees;
    use MakesPlans;

    private const array STARTS_WORK_OR_STAFFS = [
        'create_nervous_system_plan',
        'approve_nervous_system_plan',
        'hire_agent',
        'grant_agent_tools',
    ];

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'workflow'];

    private Users $admin;
    private Users $max;
    private Users $jenn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootCompanyBrain();
        $this->admin = $this->makeAdmin();
        $this->max = $this->makeEmployee();
        $this->jenn = $this->makeEmployee();
        Bus::fake();
    }

    public function testAnAttendedTurnCanDelegate(): void
    {
        $names = $this->toolNames($this->brainHandler($this->admin));

        foreach ([
            'create_nervous_system_plan',
            'add_nervous_system_task',
            'assign_nervous_system_plan',
            'assign_nervous_system_task',
            'update_nervous_system_plan',
            'read_plan_activity',
            'comment_on_nervous_system_plan',
            'attach_file_to_plan',
            'list_plan_files',
            'list_agents',
            'hire_agent',
            'grant_agent_tools',
        ] as $name) {
            $this->assertContains($name, $names);
        }
    }

    public function testAnUnattendedTurnCannotStartWorkOrStaffEvenWithRegistryGrants(): void
    {
        $this->grantFromCatalog($this->brain, [
            CreateNervousSystemPlanTool::class,
            HireAgentTool::class,
            GrantAgentToolsTool::class,
        ]);

        $names = $this->toolNames($this->brainHandler($this->admin, unattended: true));

        foreach (self::STARTS_WORK_OR_STAFFS as $name) {
            $this->assertNotContains($name, $names);
        }
        $this->assertContains('read_plan_activity', $names);
        $this->assertContains('assign_nervous_system_plan', $names);
    }

    public function testARegistryGrantDoesNotReplaceTheOperationsDefault(): void
    {
        $this->grantFromCatalog($this->brain, [CreateNervousSystemPlanTool::class]);
        $handler = $this->brainHandler($this->admin, $this->sessionWithBrain($this->admin));

        $this->assertCount(1, array_keys($this->toolNames($handler), 'create_nervous_system_plan', true));

        $result = $this->tool($handler, 'create_nervous_system_plan')(title: 'Prepare the October invoice for Acme');

        $this->assertTrue(Plan::getById((int) $result['plan_id'])->project->isCompanyOperations());
    }

    public function testBrainIsThePlansCreatorOnBothRoutes(): void
    {
        $project = $this->projectManagedBy($this->makeAgent());
        $tool = $this->tool($this->brainHandler($this->admin, $this->sessionWithBrain($this->admin)), 'create_nervous_system_plan');

        $onOperations = Plan::getById((int) $tool(title: 'Invoice for Acme')['plan_id']);
        $onProject = Plan::getById((int) $tool(title: 'Ship the landing page', project_id: $project->getId())['plan_id']);

        $this->assertSame($this->brain->getId(), $onOperations->created_by_agent_id);
        $this->assertSame($this->brain->getId(), $onProject->created_by_agent_id);
        $this->assertSame($project->getId(), (int) $onProject->project_id);
    }

    public function testTwoPeopleAskingForTheSameTitleGetTwoPlans(): void
    {
        $max = $this->tool($this->brainHandler($this->max, $this->sessionWithBrain($this->max)), 'create_nervous_system_plan');
        $jenn = $this->tool($this->brainHandler($this->jenn, $this->sessionWithBrain($this->jenn)), 'create_nervous_system_plan');

        $maxPlan = Plan::getById((int) $max(title: 'Prepare an invoice')['plan_id']);
        $jennPlan = Plan::getById((int) $jenn(title: 'Prepare an invoice')['plan_id']);

        $this->assertNotSame($maxPlan->getId(), $jennPlan->getId());
        $this->assertSame($this->max->getId(), (int) $maxPlan->origin_users_id);
        $this->assertSame($this->jenn->getId(), (int) $jennPlan->origin_users_id);
    }

    public function testTheSamePersonRepeatingATitleInOneConversationReusesThePlan(): void
    {
        $tool = $this->tool($this->brainHandler($this->max, $this->sessionWithBrain($this->max)), 'create_nervous_system_plan');

        $first = $tool(title: 'Prepare an invoice');
        $second = $tool(title: 'Prepare an invoice');

        $this->assertSame($first['plan_id'], $second['plan_id']);
        $this->assertTrue($second['reused']);
    }

    public function testTwoPeopleInOneSharedChannelAreRecordedAsTwoAskers(): void
    {
        $shared = $this->sessionWithBrain($this->human, $this->channelFor($this->human, 'Team'));
        $max = $this->tool($this->brainHandler($this->brain->user, $shared, conversationHuman: $this->max), 'create_nervous_system_plan');
        $jenn = $this->tool($this->brainHandler($this->brain->user, $shared, conversationHuman: $this->jenn), 'create_nervous_system_plan');

        $maxPlan = Plan::getById((int) $max(title: 'Prepare an invoice')['plan_id']);
        $jennPlan = Plan::getById((int) $jenn(title: 'Prepare an invoice')['plan_id']);

        $this->assertNotSame($maxPlan->getId(), $jennPlan->getId());
        $this->assertSame($this->max->getId(), (int) $maxPlan->origin_users_id);
        $this->assertSame($this->jenn->getId(), (int) $jennPlan->origin_users_id);
        $this->assertTrue($this->jenn->is($jennPlan->asker()));
    }

    public function testTwoEmployeesMentioningBrainOnItsBoardCannotStartPlansThere(): void
    {
        // A catalog grant of the same tool must not bring it back on a board turn either.
        $this->grantFromCatalog($this->brain, [CreateNervousSystemPlanTool::class]);
        $operations = new EnsureCompanyOperationsProjectAction($this->tenantApp, $this->company)->execute();

        foreach ([$this->max, $this->jenn] as $author) {
            $names = $this->toolNames($this->brainHandler($operations->owner, entity: $operations, conversationHuman: $author));

            $this->assertNotContains('create_nervous_system_plan', $names);
            $this->assertContains('add_nervous_system_task', $names);
            $this->assertContains('comment_on_nervous_system_plan', $names);
        }
    }

    public function testAProjectIdFromAnotherCompanyIsRefusedAndNothingIsCreated(): void
    {
        $foreign = $this->projectManagedBy($this->makeAgent());
        $foreign->companies_id = $this->company->getId() + 999999;
        $foreign->saveQuietly();
        $tool = $this->tool($this->brainHandler($this->admin, $this->sessionWithBrain($this->admin)), 'create_nervous_system_plan');
        $before = $this->planCount();

        $result = $tool(title: 'Anything', project_id: $foreign->getId());

        $this->assertArrayNotHasKey('plan_id', $result);
        $this->assertSame($before, $this->planCount());
    }

    public function testBrainStartsNoWorkItCouldNotReportBackOn(): void
    {
        $tool = $this->tool($this->brainHandler($this->admin), 'create_nervous_system_plan');
        $before = $this->planCount();

        $result = $tool(title: 'Prepare an invoice');

        $this->assertArrayNotHasKey('plan_id', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame($before, $this->planCount());
        $this->assertSame(0, $this->operationsBoards()->count());
    }

    public function testWithoutADefaultTheToolStillRequiresAProject(): void
    {
        $tool = new CreateNervousSystemPlanTool()
            ->withContext($this->tenantApp, $this->company, $this->admin);

        $result = $tool(title: 'No project');

        $this->assertArrayNotHasKey('plan_id', $result);
        $this->assertStringContainsString('project_id', (string) ($result['error'] ?? $result['message'] ?? ''));
    }

    public function testAnOrdinaryEmployeeCanDelegateEndToEnd(): void
    {
        $this->assertFalse($this->jenn->isAdmin());
        $worker = $this->makeAgent();
        $handler = $this->brainHandler($this->jenn, $this->sessionWithBrain($this->jenn));

        $roster = $this->tool($handler, 'list_agents')();
        $this->assertGreaterThan(0, $roster['count']);
        $this->assertArrayNotHasKey('tools', $roster['agents'][0]);
        $this->assertArrayNotHasKey('projects', $roster['agents'][0]);

        $plan = $this->tool($handler, 'create_nervous_system_plan')(title: 'Prepare an invoice');
        $assigned = $this->tool($handler, 'assign_nervous_system_plan')(
            plan_id: $plan['plan_id'],
            agent_id: $worker->getId(),
        );

        $this->assertArrayNotHasKey('error', $assigned);
        $this->assertSame($worker->getId(), Plan::getById((int) $plan['plan_id'])->agent_id);

        $hired = $this->tool($handler, 'hire_agent')(name: 'Extra', role: 'Helper', instructions: 'Help out.');
        $this->assertFalse($hired['hired'] ?? false);
    }

    public function testAnAdminStillGetsTheFullRoster(): void
    {
        $this->makeAgent();

        $roster = $this->tool($this->brainHandler($this->admin), 'list_agents')();

        $this->assertArrayHasKey('tools', $roster['agents'][0]);
    }

    public function testThePmsRosterStaysAdminOnly(): void
    {
        $roster = new ListAgentsTool($this->makeAgent())
            ->withContext($this->tenantApp, $this->company, $this->jenn)
            ->forRequestingUser($this->jenn)();

        $this->assertSame(0, $roster['count']);
        $this->assertArrayHasKey('error', $roster);
    }

    public function testBrainWorkingATaskAsAWorkerIsHeldToTheWorkerBoundary(): void
    {
        $names = WorkerToolPolicy::within(fn (): array => $this->toolNames($this->brainHandler($this->admin)));

        foreach (['create_nervous_system_plan', 'assign_nervous_system_plan', 'hire_agent', 'grant_agent_tools'] as $name) {
            $this->assertNotContains($name, $names);
        }
    }

    private function planCount(): int
    {
        return Plan::query()->fromApp($this->tenantApp)->fromCompany($this->company)->count();
    }
}
