<?php

declare(strict_types=1);

namespace Tests\GraphQL\HumanResources;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\HumanResources\Employees\Actions\CreateEmployeeAction;
use Kanvas\HumanResources\Employees\DataTransferObject\Employee as EmployeeData;
use Tests\TestCase;

class LeaveRequestTest extends TestCase
{
    use DatabaseTransactions;
    use HrTestSetup;

    protected array $connectionsToTransact = ['mysql', 'crm', 'hr', 'intelligence'];

    /**
     * Make the acting user an employee and create a leave type with the given annual allotment.
     */
    private function seedSelfAndLeaveType(int $annualDays = 15): string
    {
        $user = auth()->user();

        new CreateEmployeeAction(
            new EmployeeData(
                app: $this->hrApp(),
                company: $this->hrCompany(),
                loginUser: $user,
                people: $this->makePeople($user),
                position: $this->makePosition(),
                hiredAt: '2026-01-15',
            ),
        )->execute();

        return $this->graphQL('
            mutation($input: HrLeaveTypeInput!) { createHrLeaveType(input: $input) { id } }
        ', ['input' => ['name' => 'Vacation ' . fake()->unique()->word(), 'default_annual_days' => $annualDays, 'accrual_method' => 'ANNUAL_ALLOTMENT']])
            ->assertSuccessful()
            ->json('data.createHrLeaveType.id');
    }

    /** @return array<string, mixed> the acting user's first leave balance */
    private function myBalance(): array
    {
        return $this->graphQL('
            query { myHrProfile { leaveBalances { available_days pending_days used_days entitled_days } } }
        ')
            ->assertSuccessful()
            ->json('data.myHrProfile.leaveBalances.0');
    }

    private function request(string $typeId, string $start, string $end): object
    {
        return $this->graphQL('
            mutation($input: HrLeaveRequestInput!) {
                requestHrLeave(input: $input) { id status days }
            }
        ', ['input' => ['leave_type_id' => $typeId, 'start_date' => $start, 'end_date' => $end]]);
    }

    public function testRequestLeaveHoldsPending(): void
    {
        $typeId = $this->seedSelfAndLeaveType(15);

        $response = $this->request($typeId, '2026-03-02', '2026-03-04') // 3 inclusive days
            ->assertSuccessful();

        $this->assertEquals('PENDING', $response->json('data.requestHrLeave.status'));
        $this->assertEquals(3, $response->json('data.requestHrLeave.days'));

        $balance = $this->myBalance();
        $this->assertEquals(3, $balance['pending_days']);
        $this->assertEquals(12, $balance['available_days']);
    }

    public function testApproveLeaveMovesPendingToUsed(): void
    {
        $typeId = $this->seedSelfAndLeaveType(15);
        $requestId = $this->request($typeId, '2026-03-02', '2026-03-04')->assertSuccessful()->json('data.requestHrLeave.id');

        $this->graphQL('
            mutation($id: ID!) { decideHrLeaveRequest(id: $id, decision: APPROVE) { status } }
        ', ['id' => $requestId])
            ->assertSuccessful()
            ->assertJson(['data' => ['decideHrLeaveRequest' => ['status' => 'APPROVED']]]);

        $balance = $this->myBalance();
        $this->assertEquals(0, $balance['pending_days']);
        $this->assertEquals(3, $balance['used_days']);
        $this->assertEquals(12, $balance['available_days']);
    }

    public function testRejectLeaveReturnsTheHold(): void
    {
        $typeId = $this->seedSelfAndLeaveType(15);
        $requestId = $this->request($typeId, '2026-03-02', '2026-03-04')->assertSuccessful()->json('data.requestHrLeave.id');

        $this->graphQL('
            mutation($id: ID!) { decideHrLeaveRequest(id: $id, decision: REJECT) { status } }
        ', ['id' => $requestId])
            ->assertSuccessful()
            ->assertJson(['data' => ['decideHrLeaveRequest' => ['status' => 'REJECTED']]]);

        $balance = $this->myBalance();
        $this->assertEquals(0, $balance['pending_days']);
        $this->assertEquals(15, $balance['available_days']);
    }

    public function testCancelPendingReturnsTheHold(): void
    {
        $typeId = $this->seedSelfAndLeaveType(15);
        $requestId = $this->request($typeId, '2026-03-02', '2026-03-04')->assertSuccessful()->json('data.requestHrLeave.id');

        $this->graphQL('
            mutation($id: ID!) { cancelHrLeaveRequest(id: $id) { status } }
        ', ['id' => $requestId])
            ->assertSuccessful()
            ->assertJson(['data' => ['cancelHrLeaveRequest' => ['status' => 'CANCELLED']]]);

        $this->assertEquals(15, $this->myBalance()['available_days']);
    }

    public function testInsufficientBalanceIsBlocked(): void
    {
        $typeId = $this->seedSelfAndLeaveType(15);

        $response = $this->request($typeId, '2026-03-01', '2026-03-25'); // 25 days > 15

        $this->assertStringContainsString('Not enough', json_encode($response->json('errors')));
    }
}
