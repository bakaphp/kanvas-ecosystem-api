<?php

declare(strict_types=1);

namespace Tests\GraphQL\HumanResources;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\HumanResources\Employees\Actions\CreateEmployeeAction;
use Kanvas\HumanResources\Employees\DataTransferObject\Employee as EmployeeData;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\HumanResources\HumanResourcesAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\AssignLeavePolicyTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\CreateLeaveTypeTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\GetEmployeeLeaveBalanceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\ListLeaveTypesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\RequestLeaveTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\SetEmployeeLeaveBalanceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\UpdateLeaveTypeTool;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Users\Models\Users;
use ReflectionMethod;
use Tests\TestCase;

class LeaveAdminToolsTest extends TestCase
{
    use DatabaseTransactions;
    use HrTestSetup;

    protected array $connectionsToTransact = ['mysql', 'crm', 'hr', 'intelligence', 'social'];

    private function context(): array
    {
        $user = auth()->user();

        return [app(Apps::class), $user->getCurrentCompany(), $user];
    }

    private function selfEmployee(): Employee
    {
        $user = auth()->user();

        return new CreateEmployeeAction(
            new EmployeeData(
                app: $this->hrApp(),
                company: $this->hrCompany(),
                loginUser: $user,
                people: $this->makePeople($user),
                position: $this->makePosition(),
                hiredAt: '2026-01-15',
            ),
        )->execute();
    }

    private function makePolicy(int|float|null $days = 12): string
    {
        [$app, $company, $user] = $this->context();
        $name = 'Vacation ' . fake()->unique()->word();

        $result = new CreateLeaveTypeTool()->withContext($app, $company, $user)
            ->__invoke($name, default_annual_days: $days);

        $this->assertTrue($result['created']);

        return $name;
    }

    public function testCreateLeaveTypeIsIdempotentAndListedBack(): void
    {
        [$app, $company, $user] = $this->context();
        $name = $this->makePolicy(18);

        $again = new CreateLeaveTypeTool()->withContext($app, $company, $user)->__invoke($name, default_annual_days: 5);
        $this->assertFalse($again['created']);
        $this->assertTrue($again['already_exists']);
        $this->assertEquals(18.0, $again['default_annual_days'], 'a repeat call must not overwrite the policy');

        $listed = new ListLeaveTypesTool()->withContext($app, $company, $user)->__invoke();
        $this->assertContains($name, array_column($listed['leave_types'], 'name'));
    }

    public function testCreateLeaveTypeRejectsAnUnknownAccrualMethod(): void
    {
        [$app, $company, $user] = $this->context();

        $result = new CreateLeaveTypeTool()->withContext($app, $company, $user)
            ->__invoke('Sabbatical ' . fake()->unique()->word(), accrual_method: 'every_full_moon');

        $this->assertFalse($result['created']);
        $this->assertStringContainsString('annual_allotment', $result['message']);
    }

    public function testUpdateLeaveTypeChangesOnlyThePassedFields(): void
    {
        [$app, $company, $user] = $this->context();
        $name = $this->makePolicy(12);

        $updated = new UpdateLeaveTypeTool()->withContext($app, $company, $user)
            ->__invoke($name, default_annual_days: 20);

        $this->assertTrue($updated['updated']);
        $this->assertEquals(20.0, $updated['default_annual_days']);
        $this->assertTrue($updated['is_paid'], 'untouched fields keep their value');
        $this->assertTrue($updated['is_active']);
    }

    public function testAssignLeavePolicySeedsTheBalanceAndIsSafeToRepeat(): void
    {
        $employee = $this->selfEmployee();
        [$app, $company, $user] = $this->context();
        $name = $this->makePolicy(12);

        $assigned = new AssignLeavePolicyTool()->withContext($app, $company, $user)
            ->__invoke($name, employee_id: $employee->getId(), year: 2026);

        $this->assertTrue($assigned['updated']);
        $this->assertTrue($assigned['assigned']);
        $this->assertEquals(12.0, $assigned['balance']['entitled_days']);
        $this->assertEquals(12.0, $assigned['balance']['available_days']);

        $again = new AssignLeavePolicyTool()->withContext($app, $company, $user)
            ->__invoke($name, employee_id: $employee->getId(), year: 2026);

        $this->assertTrue($again['updated']);
        $this->assertFalse($again['assigned']);
        $this->assertEquals(12.0, $again['balance']['entitled_days']);
    }

    public function testAssignLeavePolicyAcceptsAProRatedOverride(): void
    {
        $employee = $this->selfEmployee();
        [$app, $company, $user] = $this->context();
        $name = $this->makePolicy(12);

        $assigned = new AssignLeavePolicyTool()->withContext($app, $company, $user)
            ->__invoke(
                $name,
                employee_id: $employee->getId(),
                year: 2026,
                entitled_days: 4.5,
            );

        $this->assertTrue($assigned['updated']);
        $this->assertEquals(4.5, $assigned['balance']['entitled_days']);
    }

    public function testAssignLeavePolicyUnblocksARequestThatFailedForLackOfBalance(): void
    {
        // The gap that started this: request_leave needs a balance, and nothing could create one.
        $employee = $this->selfEmployee();
        [$app, $company, $user] = $this->context();
        $name = $this->makePolicy(0);

        $blocked = new RequestLeaveTool()->withContext($app, $company, $user)
            ->__invoke(
                $name,
                '2026-04-06',
                '2026-04-08',
                employee_id: $employee->getId(),
            );
        $this->assertFalse($blocked['created']);
        $this->assertStringContainsString('Not enough', $blocked['message']);

        new SetEmployeeLeaveBalanceTool()->withContext($app, $company, $user)
            ->__invoke(
                $name,
                employee_id: $employee->getId(),
                year: 2026,
                entitled_days: 10,
            );

        $allowed = new RequestLeaveTool()->withContext($app, $company, $user)
            ->__invoke(
                $name,
                '2026-04-06',
                '2026-04-08',
                employee_id: $employee->getId(),
            );
        $this->assertTrue($allowed['created']);
        $this->assertEquals(3, $allowed['days']);
    }

    public function testSetEmployeeLeaveBalanceSetsThenAdjustsRelatively(): void
    {
        $employee = $this->selfEmployee();
        [$app, $company, $user] = $this->context();
        $name = $this->makePolicy(12);

        $set = new SetEmployeeLeaveBalanceTool()->withContext($app, $company, $user)
            ->__invoke(
                $name,
                employee_id: $employee->getId(),
                year: 2026,
                entitled_days: 20,
            );
        $this->assertTrue($set['updated']);
        $this->assertEquals(20.0, $set['balance']['entitled_days']);

        $bumped = new SetEmployeeLeaveBalanceTool()->withContext($app, $company, $user)
            ->__invoke(
                $name,
                employee_id: $employee->getId(),
                year: 2026,
                adjust_days: 2.5,
            );
        $this->assertTrue($bumped['updated']);
        $this->assertEquals(22.5, $bumped['balance']['entitled_days']);

        $docked = new SetEmployeeLeaveBalanceTool()->withContext($app, $company, $user)
            ->__invoke(
                $name,
                employee_id: $employee->getId(),
                year: 2026,
                adjust_days: -2.5,
            );
        $this->assertEquals(20.0, $docked['balance']['entitled_days']);

        $read = new GetEmployeeLeaveBalanceTool()->withContext($app, $company, $user)
            ->__invoke(employee_id: $employee->getId(), year: 2026);
        $this->assertEquals(20.0, $read['balances'][0]['available_days']);
    }

    public function testSetEmployeeLeaveBalanceCannotDropBelowWhatIsUsedOrPending(): void
    {
        $employee = $this->selfEmployee();
        [$app, $company, $user] = $this->context();
        $name = $this->makePolicy(12);

        $request = new RequestLeaveTool()->withContext($app, $company, $user)
            ->__invoke(
                $name,
                '2026-06-01',
                '2026-06-05',
                employee_id: $employee->getId(),
            );
        $this->assertTrue($request['created']);

        $rejected = new SetEmployeeLeaveBalanceTool()->withContext($app, $company, $user)
            ->__invoke(
                $name,
                employee_id: $employee->getId(),
                year: 2026,
                entitled_days: 2,
            );

        $this->assertFalse($rejected['updated']);
        $this->assertStringContainsString('already used or pending', $rejected['message']);

        $negative = new SetEmployeeLeaveBalanceTool()->withContext($app, $company, $user)
            ->__invoke(
                $name,
                employee_id: $employee->getId(),
                year: 2026,
                adjust_days: -100,
            );
        $this->assertFalse($negative['updated']);
        $this->assertStringContainsString('cannot be negative', $negative['message']);
    }

    public function testSetEmployeeLeaveBalanceRefusesANoOpCall(): void
    {
        $employee = $this->selfEmployee();
        [$app, $company, $user] = $this->context();
        $name = $this->makePolicy(12);

        $result = new SetEmployeeLeaveBalanceTool()->withContext($app, $company, $user)
            ->__invoke($name, employee_id: $employee->getId());

        $this->assertFalse($result['updated']);
        $this->assertStringContainsString('assign_leave_policy', $result['message']);
    }

    public function testLeaveWritesAgainstAnUnknownPolicyReturnAStructuredError(): void
    {
        $employee = $this->selfEmployee();
        [$app, $company, $user] = $this->context();

        $assign = new AssignLeavePolicyTool()->withContext($app, $company, $user)
            ->__invoke('Moon Leave', employee_id: $employee->getId());
        $this->assertFalse($assign['updated']);
        $this->assertStringContainsString('list_leave_types', $assign['message']);

        $set = new SetEmployeeLeaveBalanceTool()->withContext($app, $company, $user)
            ->__invoke('Moon Leave', employee_id: $employee->getId(), entitled_days: 5);
        $this->assertFalse($set['updated']);

        $unknownEmployee = new AssignLeavePolicyTool()->withContext($app, $company, $user)
            ->__invoke($this->makePolicy(5), employee_email: 'nobody-' . fake()->unique()->safeEmail());
        $this->assertFalse($unknownEmployee['updated']);
        $this->assertStringContainsString('find_employee', $unknownEmployee['message']);
    }

    public function testEveryLeaveAdminToolRejectsANonAdmin(): void
    {
        $employee = $this->selfEmployee();
        [$app, $company, $user] = $this->context();
        $name = $this->makePolicy(12);
        // A bare factory user has no roles → isAdmin() is false.
        $nonAdmin = Users::factory()->create();

        $create = new CreateLeaveTypeTool()->withContext($app, $company, $nonAdmin)->__invoke('Nope Leave');
        $this->assertFalse($create['created']);

        $update = new UpdateLeaveTypeTool()->withContext($app, $company, $nonAdmin)
            ->__invoke($name, default_annual_days: 99);
        $this->assertFalse($update['updated']);

        $assign = new AssignLeavePolicyTool()->withContext($app, $company, $nonAdmin)
            ->__invoke($name, employee_id: $employee->getId());
        $this->assertFalse($assign['updated']);

        $set = new SetEmployeeLeaveBalanceTool()->withContext($app, $company, $nonAdmin)
            ->__invoke($name, employee_id: $employee->getId(), entitled_days: 99);
        $this->assertFalse($set['updated']);

        // The agent's own user being an admin must not authorize a non-admin human either.
        $viaAgent = new SetEmployeeLeaveBalanceTool()->withContext($app, $company, $user)
            ->forRequestingUser($nonAdmin)
            ->__invoke($name, employee_id: $employee->getId(), entitled_days: 99);
        $this->assertFalse($viaAgent['updated']);
        $this->assertStringContainsString('administrator', $viaAgent['message']);
    }

    public function testBalanceWritesLandOnTheLedgerAndTheEmployeeHistory(): void
    {
        $employee = $this->selfEmployee();
        [$app, $company, $user] = $this->context();
        $name = $this->makePolicy(12);

        new AssignLeavePolicyTool()->withContext($app, $company, $user)
            ->__invoke($name, employee_id: $employee->getId(), year: 2026);

        $assignedEvent = Event::query()
            ->where('source_domain', 'HumanResources')
            ->where('event_type', 'leave.policy.assigned')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($assignedEvent);
        $this->assertEquals($name, $assignedEvent->payload['leave_type']);

        new SetEmployeeLeaveBalanceTool()->withContext($app, $company, $user)
            ->__invoke(
                $name,
                employee_id: $employee->getId(),
                year: 2026,
                adjust_days: 3,
                reason: 'Tenure bonus',
            );

        $adjustedEvent = Event::query()
            ->where('source_domain', 'HumanResources')
            ->where('event_type', 'leave.balance.adjusted')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($adjustedEvent);
        $this->assertEquals('Tenure bonus', $adjustedEvent->payload['reason']);
        $this->assertEquals(15.0, $adjustedEvent->payload['entitled_days']);
    }

    /**
     * The HR Operations agent type has NO rows in nervous_system_tool_agent_types — its toolset comes
     * entirely from the hardcoded baseline in HumanResourcesAgent::tools(). So "the agent has the tool"
     * is only true if the class actually hands it out, and nothing else in the stack asserts that.
     */
    public function testTheHrAgentActuallyHandsOutTheLeaveAdminTools(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['provider' => 'neuron', 'handler' => HumanResourcesAgent::class]);

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $agentType->getId(), 'user_id' => $user->getId()]);

        $handler = HumanResourcesAgent::make();
        $handler->setConfiguration(agent: $agent, user: $user);

        $reflected = new ReflectionMethod($handler, 'tools');
        $names = array_map(
            static fn (object $tool): string => method_exists($tool, 'getName') ? $tool->getName() : $tool::class,
            $reflected->invoke($handler),
        );

        foreach ([
            'list_leave_types',
            'create_leave_type',
            'update_leave_type',
            'assign_leave_policy',
            'set_employee_leave_balance',
            'list_leave_requests',
            'decide_leave',
            'cancel_leave',
        ] as $expected) {
            $this->assertContains($expected, $names, "The HR agent must expose {$expected}.");
        }
    }
}
