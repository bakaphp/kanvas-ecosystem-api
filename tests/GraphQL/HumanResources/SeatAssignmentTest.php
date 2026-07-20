<?php

declare(strict_types=1);

namespace Tests\GraphQL\HumanResources;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SeatAssignmentTest extends TestCase
{
    use DatabaseTransactions;
    use HrTestSetup;

    protected array $connectionsToTransact = ['mysql', 'crm', 'hr', 'intelligence', 'social'];

    private function createEmployeeId(): string
    {
        return $this->graphQL('
            mutation($input: HrEmployeeInput!) { createHrEmployee(input: $input) { id } }
        ', ['input' => $this->makeEmployeeInput()])
            ->assertSuccessful()
            ->json('data.createHrEmployee.id');
    }

    private function createDepartmentId(): string
    {
        return $this->graphQL('
            mutation($input: HrDepartmentInput!) { createHrDepartment(input: $input) { id } }
        ', ['input' => ['name' => 'Dept ' . fake()->unique()->word()]])
            ->assertSuccessful()
            ->json('data.createHrDepartment.id');
    }

    public function testAssignSeat(): void
    {
        $empId = $this->createEmployeeId();
        $deptId = $this->createDepartmentId();

        $this->graphQL('
            mutation($input: HrSeatAssignmentInput!) {
                assignHrSeat(input: $input) {
                    id
                    allocation_pct
                    is_primary
                    employee { id }
                    department { id }
                }
            }
        ', ['input' => ['employee_id' => $empId, 'department_id' => $deptId, 'allocation_pct' => 50]])
            ->assertSuccessful()
            ->assertJson(['data' => ['assignHrSeat' => [
                'allocation_pct' => 50,
                'is_primary' => true,
                'employee' => ['id' => $empId],
                'department' => ['id' => $deptId],
            ]]]);
    }

    public function testPrimarySeatSetsEmployeeDepartment(): void
    {
        $empId = $this->createEmployeeId();
        $deptId = $this->createDepartmentId();

        $this->graphQL('
            mutation($input: HrSeatAssignmentInput!) {
                assignHrSeat(input: $input) {
                    employee { department { id } }
                }
            }
        ', ['input' => ['employee_id' => $empId, 'department_id' => $deptId, 'is_primary' => true]])
            ->assertSuccessful()
            ->assertJson(['data' => ['assignHrSeat' => ['employee' => ['department' => ['id' => $deptId]]]]]);
    }

    public function testNewPrimaryDemotesOldPrimary(): void
    {
        $empId = $this->createEmployeeId();
        $dept1 = $this->createDepartmentId();
        $dept2 = $this->createDepartmentId();

        $this->graphQL('
            mutation($input: HrSeatAssignmentInput!) { assignHrSeat(input: $input) { id } }
        ', ['input' => ['employee_id' => $empId, 'department_id' => $dept1, 'is_primary' => true]])
            ->assertSuccessful();

        $response = $this->graphQL('
            mutation($input: HrSeatAssignmentInput!) {
                assignHrSeat(input: $input) {
                    employee {
                        seatAssignments { is_primary department { id } }
                    }
                }
            }
        ', ['input' => ['employee_id' => $empId, 'department_id' => $dept2, 'is_primary' => true]])
            ->assertSuccessful();

        $seats = $response->json('data.assignHrSeat.employee.seatAssignments');
        $primaries = array_values(array_filter($seats, fn ($s) => $s['is_primary'] === true));

        $this->assertCount(1, $primaries);
        $this->assertEquals($dept2, $primaries[0]['department']['id']);
    }

    public function testEndSeat(): void
    {
        $empId = $this->createEmployeeId();
        $deptId = $this->createDepartmentId();

        $seatId = $this->graphQL('
            mutation($input: HrSeatAssignmentInput!) { assignHrSeat(input: $input) { id } }
        ', ['input' => ['employee_id' => $empId, 'department_id' => $deptId]])
            ->assertSuccessful()
            ->json('data.assignHrSeat.id');

        $this->graphQL('
            mutation($id: ID!) {
                endHrSeat(id: $id) { id effective_to }
            }
        ', ['id' => $seatId])
            ->assertSuccessful()
            ->assertJsonPath('data.endHrSeat.id', $seatId);
    }
}
