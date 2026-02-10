<?php

declare(strict_types=1);

namespace Tests\GraphQL\ActionEngine;

use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\Apps\Models\Apps;
use Tests\TestCase;

class ActionCrudTest extends TestCase
{
    public function testCreateAction(): void
    {
        $input = [
            'name' => 'Test Action ' . fake()->word(),
            'description' => 'Test action description',
            'is_active' => true,
            'is_published' => true,
            'collects_info' => false,
        ];

        $response = $this->graphQL('
            mutation($input: ActionInput!) {
                createAction(input: $input) {
                    id
                    name
                    slug
                    description
                    is_active
                    is_published
                    collects_info
                    pipeline {
                        id
                        name
                        stages {
                            id
                            name
                        }
                    }
                }
            }
        ', ['input' => $input])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createAction' => [
                    'name' => $input['name'],
                    'description' => $input['description'],
                    'is_active' => true,
                    'is_published' => true,
                    'collects_info' => false,
                ],
            ],
        ]);

        $pipeline = $response->json('data.createAction.pipeline');
        $this->assertNotNull($pipeline);
        $this->assertNotNull($pipeline['id']);
        $this->assertCount(3, $pipeline['stages']);
    }

    public function testCreateActionWithAllFields(): void
    {
        $input = [
            'name' => 'Full Action ' . fake()->word(),
            'description' => 'Full action description',
            'icon' => 'icon-test',
            'form_fields' => ['field1' => 'value1'],
            'form_config' => ['config1' => 'value1'],
            'is_active' => true,
            'is_published' => true,
            'collects_info' => true,
        ];

        $this->graphQL('
            mutation($input: ActionInput!) {
                createAction(input: $input) {
                    id
                    name
                    slug
                    description
                    icon
                    form_fields
                    form_config
                    is_active
                    is_published
                    collects_info
                }
            }
        ', ['input' => $input])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createAction' => [
                    'name' => $input['name'],
                    'description' => $input['description'],
                    'is_active' => true,
                    'is_published' => true,
                    'collects_info' => true,
                ],
            ],
        ]);
    }

    public function testUpdateAction(): void
    {
        $createInput = [
            'name' => 'Action To Update ' . fake()->word(),
            'description' => 'Original description',
            'is_active' => true,
            'is_published' => true,
        ];

        $createResponse = $this->graphQL('
            mutation($input: ActionInput!) {
                createAction(input: $input) {
                    id
                    name
                }
            }
        ', ['input' => $createInput])->assertSuccessful();

        $actionId = $createResponse->json('data.createAction.id');

        $updateInput = [
            'name' => 'Updated Action ' . fake()->word(),
            'description' => 'Updated description',
            'is_active' => false,
        ];

        $this->graphQL('
            mutation($id: ID!, $input: UpdateActionInput!) {
                updateAction(id: $id, input: $input) {
                    id
                    name
                    description
                    is_active
                }
            }
        ', [
            'id' => $actionId,
            'input' => $updateInput,
        ])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateAction' => [
                    'id' => $actionId,
                    'name' => $updateInput['name'],
                    'description' => 'Updated description',
                    'is_active' => false,
                ],
            ],
        ]);
    }

    public function testDeleteAction(): void
    {
        $createInput = [
            'name' => 'Action To Delete ' . fake()->word(),
            'description' => 'Will be deleted',
            'is_active' => true,
            'is_published' => true,
        ];

        $createResponse = $this->graphQL('
            mutation($input: ActionInput!) {
                createAction(input: $input) {
                    id
                }
            }
        ', ['input' => $createInput])->assertSuccessful();

        $actionId = $createResponse->json('data.createAction.id');

        $this->graphQL('
            mutation($id: ID!) {
                deleteAction(id: $id)
            }
        ', ['id' => $actionId])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'deleteAction' => true,
            ],
        ]);
    }

    public function testCannotDeleteActionInUseByCompanyAction(): void
    {
        $createInput = [
            'name' => 'Action In Use ' . fake()->word(),
            'description' => 'Should not be deletable',
            'is_active' => true,
            'is_published' => true,
        ];

        $createResponse = $this->graphQL('
            mutation($input: ActionInput!) {
                createAction(input: $input) {
                    id
                }
            }
        ', ['input' => $createInput])->assertSuccessful();

        $actionId = $createResponse->json('data.createAction.id');

        $app = app(Apps::class);
        $user = auth()->user();
        $action = Action::getById((int) $actionId, $app);

        $companyAction = new CompanyAction();
        $companyAction->actions_id = $action->getId();
        $companyAction->apps_id = $app->getId();
        $companyAction->companies_id = $user->getCurrentCompany()->getId();
        $companyAction->companies_branches_id = 0;
        $companyAction->users_id = $user->getId();
        $companyAction->pipelines_id = $action->pipelines_id;
        $companyAction->name = $action->name;
        $companyAction->is_active = true;
        $companyAction->is_published = true;
        $companyAction->saveOrFail();

        $response = $this->graphQL('
            mutation($id: ID!) {
                deleteAction(id: $id)
            }
        ', ['id' => $actionId]);

        $this->assertStringContainsString(
            'Cannot delete action that is in use by company actions.',
            json_encode($response->json())
        );
    }

    public function testGetActions(): void
    {
        $input = [
            'name' => 'Queryable Action ' . fake()->word(),
            'description' => 'For listing test',
            'is_active' => true,
            'is_published' => true,
        ];

        $this->graphQL('
            mutation($input: ActionInput!) {
                createAction(input: $input) {
                    id
                }
            }
        ', ['input' => $input])->assertSuccessful();

        $this->graphQL('
            query {
                actionEngineActions {
                    data {
                        id
                        name
                        slug
                        description
                        icon
                        form_fields
                        form_config
                        is_active
                        is_published
                        collects_info
                        parent {
                            id
                        }
                        children {
                            id
                        }
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'actionEngineActions' => [
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'slug',
                            'description',
                            'is_active',
                            'is_published',
                            'collects_info',
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testGetActionsWithFilters(): void
    {
        $input = [
            'name' => 'Filterable Action ' . fake()->uuid(),
            'description' => 'For filter test',
            'is_active' => true,
            'is_published' => true,
        ];

        $createResponse = $this->graphQL('
            mutation($input: ActionInput!) {
                createAction(input: $input) {
                    id
                    name
                }
            }
        ', ['input' => $input])->assertSuccessful();

        $actionName = $createResponse->json('data.createAction.name');

        $this->graphQL('
            query($where: QueryActionEngineActionsWhereWhereConditions) {
                actionEngineActions(where: $where) {
                    data {
                        id
                        name
                    }
                }
            }
        ', [
            'where' => [
                'column' => 'NAME',
                'operator' => 'EQ',
                'value' => $actionName,
            ],
        ])
        ->assertSuccessful();
    }
}
