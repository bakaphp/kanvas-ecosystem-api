<?php

declare(strict_types=1);

namespace Tests\GraphQL\HumanResources;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LeaveTypeCrudTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'hr', 'intelligence', 'social'];

    public function testCreateLeaveType(): void
    {
        $name = 'Vacation ' . fake()->unique()->word();

        $this->graphQL('
            mutation($input: HrLeaveTypeInput!) {
                createHrLeaveType(input: $input) {
                    id
                    name
                    accrual_method
                    default_annual_days
                    requires_approval
                }
            }
        ', ['input' => ['name' => $name, 'default_annual_days' => 15, 'accrual_method' => 'ANNUAL_ALLOTMENT']])
            ->assertSuccessful()
            ->assertJson(['data' => ['createHrLeaveType' => [
                'name' => $name,
                'accrual_method' => 'ANNUAL_ALLOTMENT',
                'default_annual_days' => 15,
                'requires_approval' => true,
            ]]]);
    }

    public function testUpdateLeaveType(): void
    {
        $id = $this->graphQL('
            mutation($input: HrLeaveTypeInput!) { createHrLeaveType(input: $input) { id } }
        ', ['input' => ['name' => 'Sick ' . fake()->unique()->word(), 'default_annual_days' => 5]])
            ->assertSuccessful()
            ->json('data.createHrLeaveType.id');

        $this->graphQL('
            mutation($id: ID!, $input: UpdateHrLeaveTypeInput!) {
                updateHrLeaveType(id: $id, input: $input) { id default_annual_days is_active }
            }
        ', ['id' => $id, 'input' => ['default_annual_days' => 8, 'is_active' => false]])
            ->assertSuccessful()
            ->assertJson(['data' => ['updateHrLeaveType' => ['default_annual_days' => 8, 'is_active' => false]]]);
    }

    public function testListLeaveTypes(): void
    {
        $this->graphQL('
            query { hrLeaveTypes { data { id name } } }
        ')
            ->assertSuccessful()
            ->assertJsonStructure(['data' => ['hrLeaveTypes' => ['data']]]);
    }
}
