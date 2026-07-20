<?php

declare(strict_types=1);

namespace Tests\GraphQL\HumanResources;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class EmployeeCrudTest extends TestCase
{
    use DatabaseTransactions;
    use HrTestSetup;

    protected array $connectionsToTransact = ['mysql', 'crm', 'hr', 'intelligence'];

    public function testCreateEmployee(): void
    {
        $input = $this->makeEmployeeInput(['employment_type' => 'EMPLOYEE', 'status' => 'ACTIVE']);

        $this->graphQL('
            mutation($input: HrEmployeeInput!) {
                createHrEmployee(input: $input) {
                    id
                    employment_type
                    status
                    people { id }
                    position { id }
                    user { id }
                }
            }
        ', ['input' => $input])
            ->assertSuccessful()
            ->assertJson(['data' => ['createHrEmployee' => [
                'employment_type' => 'EMPLOYEE',
                'status' => 'ACTIVE',
                'people' => ['id' => $input['people_id']],
                'position' => ['id' => $input['position_id']],
                'user' => ['id' => $input['users_id']],
            ]]]);
    }

    public function testCreateEmployeeWithManager(): void
    {
        $managerId = $this->graphQL('
            mutation($input: HrEmployeeInput!) {
                createHrEmployee(input: $input) { id }
            }
        ', ['input' => $this->makeEmployeeInput()])
            ->assertSuccessful()
            ->json('data.createHrEmployee.id');

        $this->graphQL('
            mutation($input: HrEmployeeInput!) {
                createHrEmployee(input: $input) {
                    id
                    manager { id }
                }
            }
        ', ['input' => $this->makeEmployeeInput(['manager_employee_id' => $managerId])])
            ->assertSuccessful()
            ->assertJson(['data' => ['createHrEmployee' => ['manager' => ['id' => $managerId]]]]);
    }

    public function testUpdateEmployee(): void
    {
        $id = $this->graphQL('
            mutation($input: HrEmployeeInput!) {
                createHrEmployee(input: $input) { id }
            }
        ', ['input' => $this->makeEmployeeInput()])
            ->assertSuccessful()
            ->json('data.createHrEmployee.id');

        $this->graphQL('
            mutation($id: ID!, $input: UpdateHrEmployeeInput!) {
                updateHrEmployee(id: $id, input: $input) { id status }
            }
        ', ['id' => $id, 'input' => ['status' => 'ON_LEAVE']])
            ->assertSuccessful()
            ->assertJson(['data' => ['updateHrEmployee' => ['status' => 'ON_LEAVE']]]);
    }

    public function testDeleteEmployee(): void
    {
        $id = $this->graphQL('
            mutation($input: HrEmployeeInput!) {
                createHrEmployee(input: $input) { id }
            }
        ', ['input' => $this->makeEmployeeInput()])
            ->assertSuccessful()
            ->json('data.createHrEmployee.id');

        $this->graphQL('
            mutation($id: ID!) { deleteHrEmployee(id: $id) }
        ', ['id' => $id])
            ->assertSuccessful()
            ->assertJson(['data' => ['deleteHrEmployee' => true]]);
    }

    public function testListEmployees(): void
    {
        $this->graphQL('
            query {
                hrEmployees {
                    data { id employment_type status }
                }
            }
        ')
            ->assertSuccessful()
            ->assertJsonStructure(['data' => ['hrEmployees' => ['data']]]);
    }
}
