<?php

declare(strict_types=1);

namespace Tests\GraphQL\HumanResources;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DepartmentCrudTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'hr', 'intelligence', 'social'];

    public function testCreateDepartment(): void
    {
        $name = 'Engineering ' . fake()->unique()->word();

        $this->graphQL('
            mutation($input: HrDepartmentInput!) {
                createHrDepartment(input: $input) {
                    id
                    name
                    slug
                }
            }
        ', ['input' => ['name' => $name]])
            ->assertSuccessful()
            ->assertJson(['data' => ['createHrDepartment' => ['name' => $name]]]);
    }

    public function testCreateChildDepartment(): void
    {
        $parentId = $this->graphQL('
            mutation($input: HrDepartmentInput!) {
                createHrDepartment(input: $input) { id }
            }
        ', ['input' => ['name' => 'Parent ' . fake()->unique()->word()]])
            ->assertSuccessful()
            ->json('data.createHrDepartment.id');

        $this->graphQL('
            mutation($input: HrDepartmentInput!) {
                createHrDepartment(input: $input) {
                    id
                    parent { id }
                }
            }
        ', ['input' => ['name' => 'Child ' . fake()->unique()->word(), 'parent_id' => $parentId]])
            ->assertSuccessful()
            ->assertJson(['data' => ['createHrDepartment' => ['parent' => ['id' => $parentId]]]]);
    }

    public function testUpdateDepartment(): void
    {
        $id = $this->graphQL('
            mutation($input: HrDepartmentInput!) {
                createHrDepartment(input: $input) { id }
            }
        ', ['input' => ['name' => 'Dept ' . fake()->unique()->word()]])
            ->assertSuccessful()
            ->json('data.createHrDepartment.id');

        $newName = 'Renamed ' . fake()->unique()->word();

        $this->graphQL('
            mutation($id: ID!, $input: UpdateHrDepartmentInput!) {
                updateHrDepartment(id: $id, input: $input) { id name }
            }
        ', ['id' => $id, 'input' => ['name' => $newName]])
            ->assertSuccessful()
            ->assertJson(['data' => ['updateHrDepartment' => ['name' => $newName]]]);
    }

    public function testDeleteDepartment(): void
    {
        $id = $this->graphQL('
            mutation($input: HrDepartmentInput!) {
                createHrDepartment(input: $input) { id }
            }
        ', ['input' => ['name' => 'Dept ' . fake()->unique()->word()]])
            ->assertSuccessful()
            ->json('data.createHrDepartment.id');

        $this->graphQL('
            mutation($id: ID!) { deleteHrDepartment(id: $id) }
        ', ['id' => $id])
            ->assertSuccessful()
            ->assertJson(['data' => ['deleteHrDepartment' => true]]);
    }

    public function testListDepartments(): void
    {
        $this->graphQL('
            query {
                hrDepartments {
                    data { id name slug }
                }
            }
        ')
            ->assertSuccessful()
            ->assertJsonStructure(['data' => ['hrDepartments' => ['data']]]);
    }
}
