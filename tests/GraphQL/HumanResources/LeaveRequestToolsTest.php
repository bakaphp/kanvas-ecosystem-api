<?php

declare(strict_types=1);

namespace Tests\GraphQL\HumanResources;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\HumanResources\Employees\Actions\CreateEmployeeAction;
use Kanvas\HumanResources\Employees\DataTransferObject\Employee as EmployeeData;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Leave\Models\LeaveRequest;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\AssignLeavePolicyTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\CancelLeaveRequestTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\CreateLeaveTypeTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\DecideLeaveRequestTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\GetEmployeeLeaveBalanceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\ListLeaveRequestsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\RequestLeaveTool;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class LeaveRequestToolsTest extends TestCase
{
    use DatabaseTransactions;
    use HrTestSetup;

    protected array $connectionsToTransact = ['mysql', 'crm', 'hr', 'intelligence', 'social'];

    private function context(): array
    {
        $user = auth()->user();

        return [app(Apps::class), $user->getCurrentCompany(), $user];
    }

    private function employeeFor(Users $user): Employee
    {
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

    private function selfEmployee(): Employee
    {
        return $this->employeeFor(auth()->user());
    }

    /**
     * A funded employee with one PENDING request against a fresh policy.
     *
     * @return array{0: Employee, 1: string, 2: int}
     */
    private function pendingRequest(int $days = 12): array
    {
        $employee = $this->selfEmployee();
        [$app, $company, $user] = $this->context();
        $name = 'Vacation ' . fake()->unique()->word();

        new CreateLeaveTypeTool()->withContext($app, $company, $user)
            ->__invoke($name, default_annual_days: $days);

        new AssignLeavePolicyTool()->withContext($app, $company, $user)
            ->__invoke($name, employee_id: $employee->getId(), year: 2026);

        $request = new RequestLeaveTool()->withContext($app, $company, $user)
            ->__invoke(
                $name,
                '2026-03-02',
                '2026-03-04',
                employee_id: $employee->getId(),
            );

        $this->assertTrue($request['created']);

        return [$employee, $name, (int) $request['leave_request_id']];
    }

    public function testListLeaveRequestsReturnsTheIdNeededToDecide(): void
    {
        [$employee, , $requestId] = $this->pendingRequest();
        [$app, $company, $user] = $this->context();

        $listed = new ListLeaveRequestsTool()->withContext($app, $company, $user)
            ->__invoke(status: 'pending', employee_id: $employee->getId());

        $this->assertSame(1, $listed['count']);
        $this->assertSame($requestId, $listed['leave_requests'][0]['leave_request_id']);
        $this->assertSame('pending', $listed['leave_requests'][0]['status']);
        $this->assertEquals(3, $listed['leave_requests'][0]['days']);
        $this->assertSame('2026-03-02', $listed['leave_requests'][0]['start_date']);
    }

    public function testListLeaveRequestsRejectsAnUnknownStatus(): void
    {
        [$app, $company, $user] = $this->context();

        $result = new ListLeaveRequestsTool()->withContext($app, $company, $user)->__invoke(status: 'maybe');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('pending', $result['message']);
    }

    public function testApprovingMovesPendingDaysToUsed(): void
    {
        [$employee, , $requestId] = $this->pendingRequest();
        [$app, $company, $user] = $this->context();

        $decided = new DecideLeaveRequestTool()->withContext($app, $company, $user)
            ->__invoke($requestId, 'approve');

        $this->assertTrue($decided['updated']);
        $this->assertSame('approved', $decided['request']['status']);

        $balance = new GetEmployeeLeaveBalanceTool()->withContext($app, $company, $user)
            ->__invoke(employee_id: $employee->getId(), year: 2026)['balances'][0];

        $this->assertEquals(3, $balance['used_days']);
        $this->assertEquals(0, $balance['pending_days']);
        $this->assertEquals(9, $balance['available_days']);
    }

    public function testRejectingGivesThePendingDaysBack(): void
    {
        [$employee, , $requestId] = $this->pendingRequest();
        [$app, $company, $user] = $this->context();

        $decided = new DecideLeaveRequestTool()->withContext($app, $company, $user)
            ->__invoke($requestId, 'reject', 'Coverage gap that week');

        $this->assertTrue($decided['updated']);
        $this->assertSame('rejected', $decided['request']['status']);

        $balance = new GetEmployeeLeaveBalanceTool()->withContext($app, $company, $user)
            ->__invoke(employee_id: $employee->getId(), year: 2026)['balances'][0];

        $this->assertEquals(0, $balance['used_days']);
        $this->assertEquals(0, $balance['pending_days']);
        $this->assertEquals(12, $balance['available_days']);
    }

    public function testARequestCannotBeDecidedTwice(): void
    {
        [, , $requestId] = $this->pendingRequest();
        [$app, $company, $user] = $this->context();

        new DecideLeaveRequestTool()->withContext($app, $company, $user)->__invoke($requestId, 'approve');

        $again = new DecideLeaveRequestTool()->withContext($app, $company, $user)
            ->__invoke($requestId, 'reject');

        $this->assertFalse($again['updated']);
        $this->assertStringContainsString('already been decided', $again['message']);
    }

    public function testDecideRejectsABadDecisionAndAnUnknownId(): void
    {
        [, , $requestId] = $this->pendingRequest();
        [$app, $company, $user] = $this->context();

        $bad = new DecideLeaveRequestTool()->withContext($app, $company, $user)
            ->__invoke($requestId, 'maybe-later');
        $this->assertFalse($bad['updated']);
        $this->assertStringContainsString('approve', $bad['message']);

        $missing = new DecideLeaveRequestTool()->withContext($app, $company, $user)
            ->__invoke(99999999, 'approve');
        $this->assertFalse($missing['updated']);
        $this->assertStringContainsString('list_leave_requests', $missing['message']);
    }

    public function testCancellingAnApprovedRequestReturnsTheUsedDays(): void
    {
        [$employee, , $requestId] = $this->pendingRequest();
        [$app, $company, $user] = $this->context();

        new DecideLeaveRequestTool()->withContext($app, $company, $user)->__invoke($requestId, 'approve');

        $cancelled = new CancelLeaveRequestTool()->withContext($app, $company, $user)->__invoke($requestId);

        $this->assertTrue($cancelled['updated']);
        $this->assertSame('cancelled', $cancelled['request']['status']);

        $balance = new GetEmployeeLeaveBalanceTool()->withContext($app, $company, $user)
            ->__invoke(employee_id: $employee->getId(), year: 2026)['balances'][0];

        $this->assertEquals(0, $balance['used_days']);
        $this->assertEquals(12, $balance['available_days']);
    }

    public function testARejectedRequestCannotBeCancelled(): void
    {
        [, , $requestId] = $this->pendingRequest();
        [$app, $company, $user] = $this->context();

        new DecideLeaveRequestTool()->withContext($app, $company, $user)->__invoke($requestId, 'reject');

        $cancelled = new CancelLeaveRequestTool()->withContext($app, $company, $user)->__invoke($requestId);

        $this->assertFalse($cancelled['updated']);
        $this->assertStringContainsString('cannot be cancelled', $cancelled['message']);
    }

    public function testAnUnrelatedNonAdminCanNeitherDecideNorCancel(): void
    {
        [, , $requestId] = $this->pendingRequest();
        [$app, $company, $user] = $this->context();
        // A bare factory user has no roles and no employee record → neither admin nor manager.
        $outsider = Users::factory()->create();

        $decide = new DecideLeaveRequestTool()->withContext($app, $company, $user)
            ->forRequestingUser($outsider)
            ->__invoke($requestId, 'approve');
        $this->assertFalse($decide['updated']);
        $this->assertStringContainsString('manager', $decide['message']);

        $cancel = new CancelLeaveRequestTool()->withContext($app, $company, $user)
            ->forRequestingUser($outsider)
            ->__invoke($requestId);
        $this->assertFalse($cancel['updated']);

        $this->assertSame(
            'pending',
            LeaveRequest::query()->where('id', $requestId)->first()->status,
            'a refused decision must leave the request untouched',
        );
    }

    public function testManagesOnlyMatchesTheReportingLine(): void
    {
        // Employee::manages() is the whole of the non-admin approval rule, in the tool and in
        // LeaveRequestMutation@decide alike, so both answers need pinning: a colleague who is simply
        // not the manager is the realistic case for someone approving leave they should not.
        $employee = $this->selfEmployee();
        $colleague = $this->employeeFor($this->makeUser());

        $this->assertFalse($colleague->manages($employee));

        $employee->manager_employee_id = $colleague->getId();
        $employee->saveOrFail();

        $this->assertTrue($colleague->manages($employee->refresh()));
    }

    public function testAManagerCanDecideTheirOwnReportsLeave(): void
    {
        // The reason decide_leave does not use the blanket admin guard the other write tools use:
        // approving your own team's time off is exactly what a non-admin manager is supposed to do.
        [$employee, , $requestId] = $this->pendingRequest();
        [$app, $company, $user] = $this->context();

        $managerUser = $this->makeUser();
        $manager = $this->employeeFor($managerUser);

        $employee->manager_employee_id = $manager->getId();
        $employee->saveOrFail();

        $this->assertTrue($manager->manages($employee->refresh()));

        $decided = new DecideLeaveRequestTool()->withContext($app, $company, $user)
            ->forRequestingUser($managerUser)
            ->__invoke($requestId, 'approve');

        $this->assertTrue($decided['updated']);
        $this->assertSame('approved', $decided['request']['status']);
    }

    public function testDecisionsLandOnTheLedger(): void
    {
        [, , $requestId] = $this->pendingRequest();
        [$app, $company, $user] = $this->context();

        new DecideLeaveRequestTool()->withContext($app, $company, $user)->__invoke($requestId, 'approve');

        $event = Event::query()
            ->where('source_domain', 'HumanResources')
            ->where('event_type', 'leave.approved')
            ->where('source_entity_id', $requestId)
            ->first();

        $this->assertNotNull($event);
        $this->assertEquals(3, $event->payload['days']);
    }
}
