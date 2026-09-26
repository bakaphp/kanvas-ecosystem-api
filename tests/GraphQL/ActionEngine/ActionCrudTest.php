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

    public function testCreateActionWithSvgIcon(): void
    {
        $icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48">'
            . '<path d="M9.4,39.4c1.5,1.5,3.1,2.8,4.9,3.9z" fill="#E75E18"/></svg>';

        $input = [
            'name' => 'Svg Icon Action ' . fake()->word(),
            'icon' => $icon,
        ];

        $response = $this->graphQL('
            mutation($input: ActionInput!) {
                createAction(input: $input) {
                    id
                    icon
                }
            }
        ', ['input' => $input])->assertSuccessful();

        $this->assertSame($icon, $response->json('data.createAction.icon'));
        $this->assertSame($icon, Action::find($response->json('data.createAction.id'))->icon);
    }

    /**
     * `icon` is Mixed rather than String so a client can send a structured icon without breaking the
     * apps still sending raw SVG. Baka's Json cast stores an array encoded and leaves a string alone,
     * so both shapes round-trip through the same column.
     */
    public function testCreateActionWithStructuredIcon(): void
    {
        $icon = ['name' => 'star', 'type' => 'lucide', 'color' => '#FF0000'];

        $response = $this->graphQL('
            mutation($input: ActionInput!) {
                createAction(input: $input) {
                    id
                    icon
                }
            }
        ', ['input' => [
            'name' => 'Structured Icon Action ' . fake()->word(),
            'icon' => $icon,
        ]])->assertSuccessful();

        $this->assertSame($icon, $response->json('data.createAction.icon'));
        $this->assertSame($icon, Action::find($response->json('data.createAction.id'))->icon);
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

        $actionId = $createResponse->json('data.createAction.id');
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
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'actionEngineActions' => [
                    'data' => [
                        ['id' => $actionId, 'name' => $actionName],
                    ],
                ],
            ],
        ]);
    }

    /**
     * createAction stamps the current app on the row, so the list query has to union apps_id 0 with
     * the current app — scoping it to apps_id = 0 alone made every action a tenant created invisible.
     */
    public function testGetActionsReturnsCurrentAppAndGlobalActions(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();

        $appActionName = 'App Scoped Action ' . fake()->uuid();
        $appActionId = $this->graphQL('
            mutation($input: ActionInput!) {
                createAction(input: $input) {
                    id
                }
            }
        ', ['input' => ['name' => $appActionName]])->assertSuccessful()->json('data.createAction.id');

        $this->assertSame($app->getId(), Action::find($appActionId)->apps_id);

        $globalAction = new Action();
        $globalAction->apps_id = 0;
        $globalAction->companies_id = 0;
        $globalAction->users_id = $user->getId();
        $globalAction->pipelines_id = Action::find($appActionId)->pipelines_id;
        $globalAction->name = 'Global Action ' . fake()->uuid();
        $globalAction->is_active = true;
        $globalAction->is_published = true;
        $globalAction->saveOrFail();

        foreach ([$appActionId => $appActionName, $globalAction->getId() => $globalAction->name] as $id => $name) {
            $this->graphQL('
                query($where: QueryActionEngineActionsWhereWhereConditions) {
                    actionEngineActions(where: $where) {
                        data {
                            id
                        }
                    }
                }
            ', [
                'where' => [
                    'column' => 'NAME',
                    'operator' => 'EQ',
                    'value' => $name,
                ],
            ])
            ->assertSuccessful()
            ->assertJson([
                'data' => [
                    'actionEngineActions' => [
                        'data' => [
                            ['id' => (string) $id],
                        ],
                    ],
                ],
            ]);
        }
    }

    public function testGetActionsDoesNotLeakOtherAppActions(): void
    {
        $otherAppAction = new Action();
        $otherAppAction->apps_id = app(Apps::class)->getId() + 10000;
        $otherAppAction->companies_id = 0;
        $otherAppAction->users_id = auth()->user()->getId();
        $otherAppAction->pipelines_id = 0;
        $otherAppAction->name = 'Foreign App Action ' . fake()->uuid();
        $otherAppAction->is_active = true;
        $otherAppAction->is_published = true;
        $otherAppAction->saveOrFail();

        $this->graphQL('
            query($where: QueryActionEngineActionsWhereWhereConditions) {
                actionEngineActions(where: $where) {
                    data {
                        id
                    }
                }
            }
        ', [
            'where' => [
                'column' => 'NAME',
                'operator' => 'EQ',
                'value' => $otherAppAction->name,
            ],
        ])
        ->assertSuccessful()
        ->assertJsonCount(0, 'data.actionEngineActions.data');
    }
}
