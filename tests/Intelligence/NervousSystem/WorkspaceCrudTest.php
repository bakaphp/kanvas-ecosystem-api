<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\NervousSystem\Project\Models\Workspace;
use Tests\TestCase;

class WorkspaceCrudTest extends TestCase
{
    public function testCreateWorkspaceViaGraphQL(): void
    {
        $this->graphQL('
            mutation ($input: CreateNervousSystemWorkspaceInput!) {
                createNervousSystemWorkspace(input: $input) { id name slug status }
            }
        ', ['input' => ['name' => 'Marketing']])
            ->assertSuccessful()
            ->assertJson(['data' => ['createNervousSystemWorkspace' => ['name' => 'Marketing', 'status' => 'active']]]);
    }

    public function testUpdateWorkspaceViaGraphQL(): void
    {
        $id = $this->graphQL('
            mutation ($input: CreateNervousSystemWorkspaceInput!) {
                createNervousSystemWorkspace(input: $input) { id }
            }
        ', ['input' => ['name' => 'Old name']])->json('data.createNervousSystemWorkspace.id');

        $this->graphQL('
            mutation ($id: ID!, $input: UpdateNervousSystemWorkspaceInput!) {
                updateNervousSystemWorkspace(id: $id, input: $input) { id name status }
            }
        ', ['id' => $id, 'input' => ['name' => 'New name', 'status' => 'archived']])
            ->assertSuccessful()
            ->assertJson(['data' => ['updateNervousSystemWorkspace' => ['name' => 'New name', 'status' => 'archived']]]);
    }

    public function testDeleteWorkspaceViaGraphQL(): void
    {
        $id = $this->graphQL('
            mutation ($input: CreateNervousSystemWorkspaceInput!) {
                createNervousSystemWorkspace(input: $input) { id }
            }
        ', ['input' => ['name' => 'Doomed workspace']])->json('data.createNervousSystemWorkspace.id');

        $this->graphQL('
            mutation ($id: ID!) { deleteNervousSystemWorkspace(id: $id) }
        ', ['id' => $id])
            ->assertSuccessful()
            ->assertJson(['data' => ['deleteNervousSystemWorkspace' => true]]);

        $this->assertSame(
            1,
            (int) Workspace::query()->withTrashed()->where('id', $id)->value('is_deleted'),
        );
    }

    public function testListWorkspaces(): void
    {
        $this->graphQL('
            mutation ($input: CreateNervousSystemWorkspaceInput!) {
                createNervousSystemWorkspace(input: $input) { id }
            }
        ', ['input' => ['name' => 'Listed workspace']])->assertSuccessful();

        $this->graphQL('
            query { nervousSystemWorkspaces(first: 10) { data { id name } } }
        ')
            ->assertSuccessful()
            ->assertJsonStructure(['data' => ['nervousSystemWorkspaces' => ['data' => [['id', 'name']]]]]);
    }
}
