<?php

declare(strict_types=1);

namespace Tests\GraphQL\HumanResources;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PositionCrudTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'hr', 'intelligence'];

    public function testCreatePosition(): void
    {
        $title = 'Senior Engineer ' . fake()->unique()->word();

        $this->graphQL('
            mutation($input: HrPositionInput!) {
                createHrPosition(input: $input) {
                    id
                    title
                    is_active
                }
            }
        ', ['input' => ['title' => $title]])
            ->assertSuccessful()
            ->assertJson(['data' => ['createHrPosition' => ['title' => $title, 'is_active' => true]]]);
    }

    public function testCreatePositionInDepartment(): void
    {
        $departmentId = $this->graphQL('
            mutation($input: HrDepartmentInput!) {
                createHrDepartment(input: $input) { id }
            }
        ', ['input' => ['name' => 'Engineering ' . fake()->unique()->word()]])
            ->assertSuccessful()
            ->json('data.createHrDepartment.id');

        $this->graphQL('
            mutation($input: HrPositionInput!) {
                createHrPosition(input: $input) {
                    id
                    department { id }
                }
            }
        ', ['input' => ['title' => 'Backend Engineer ' . fake()->unique()->word(), 'department_id' => $departmentId]])
            ->assertSuccessful()
            ->assertJson(['data' => ['createHrPosition' => ['department' => ['id' => $departmentId]]]]);
    }

    public function testUpdatePosition(): void
    {
        $id = $this->graphQL('
            mutation($input: HrPositionInput!) {
                createHrPosition(input: $input) { id }
            }
        ', ['input' => ['title' => 'Role ' . fake()->unique()->word()]])
            ->assertSuccessful()
            ->json('data.createHrPosition.id');

        $this->graphQL('
            mutation($id: ID!, $input: UpdateHrPositionInput!) {
                updateHrPosition(id: $id, input: $input) { id level is_active }
            }
        ', ['id' => $id, 'input' => ['level' => 'IC3', 'is_active' => false]])
            ->assertSuccessful()
            ->assertJson(['data' => ['updateHrPosition' => ['level' => 'IC3', 'is_active' => false]]]);
    }

    public function testDeletePosition(): void
    {
        $id = $this->graphQL('
            mutation($input: HrPositionInput!) {
                createHrPosition(input: $input) { id }
            }
        ', ['input' => ['title' => 'Role ' . fake()->unique()->word()]])
            ->assertSuccessful()
            ->json('data.createHrPosition.id');

        $this->graphQL('
            mutation($id: ID!) { deleteHrPosition(id: $id) }
        ', ['id' => $id])
            ->assertSuccessful()
            ->assertJson(['data' => ['deleteHrPosition' => true]]);
    }

    public function testListPositions(): void
    {
        $this->graphQL('
            query {
                hrPositions {
                    data { id title }
                }
            }
        ')
            ->assertSuccessful()
            ->assertJsonStructure(['data' => ['hrPositions' => ['data']]]);
    }
}
