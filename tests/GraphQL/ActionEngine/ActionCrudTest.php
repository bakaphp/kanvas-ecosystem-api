<?php

declare(strict_types=1);

namespace Tests\GraphQL\ActionEngine;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\Apps\Models\Apps;
use Tests\TestCase;

class ActionCrudTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Actions are written on action_engine, which the default-connection-only rollback leaves
     * committed — including apps_id 0 globals, which every app's list query unions in.
     */
    protected $connectionsToTransact = [null, 'action_engine'];

    public function testCreateAction(): void
    {
        $input = [
            'name' => 'Test Action ' . fake()->uuid(),
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
            'name' => 'Full Action ' . fake()->uuid(),
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
            'name' => 'Svg Icon Action ' . fake()->uuid(),
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
            'name' => 'Structured Icon Action ' . fake()->uuid(),
            'icon' => $icon,
        ]])->assertSuccessful();

        $this->assertSame($icon, $response->json('data.createAction.icon'));
        $this->assertSame($icon, Action::find($response->json('data.createAction.id'))->icon);
    }

    public function testUpdateAction(): void
    {
        $createInput = [
            'name' => 'Action To Update ' . fake()->uuid(),
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
            'name' => 'Updated Action ' . fake()->uuid(),
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
            'name' => 'Action To Delete ' . fake()->uuid(),
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
            'name' => 'Action In Use ' . fake()->uuid(),
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
            'name' => 'Queryable Action ' . fake()->uuid(),
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

        $this->assertActionIsListed(
            $createResponse->json('data.createAction.name'),
            $createResponse->json('data.createAction.id'),
        );
    }

    /**
     * createAction stamps the acting app on the row, so the list query has to union apps_id 0 with the
     * current app rather than scoping to apps_id 0 alone.
     */
    public function testGetActionsReturnsCurrentAppAndGlobalActions(): void
    {
        $appActionName = 'App Scoped Action ' . fake()->uuid();
        $appActionId = $this->createActionWithName($appActionName);
        $globalAction = $this->createGlobalAction();

        $this->assertSame(app(Apps::class)->getId(), Action::find($appActionId)->apps_id);

        $this->assertActionIsListed($appActionName, $appActionId);
        $this->assertActionIsListed($globalAction->name, $globalAction->getId());
    }

    /**
     * The CRUD has no way to choose a tenant: every created action is pegged to the acting app, and
     * a global one has to be moved there by hand. Locked in so an input field can't silently add one.
     */
    public function testCreateActionIsPeggedToTheActingAppAndCompany(): void
    {
        $action = Action::find($this->createActionWithName('Pegged Action ' . fake()->uuid()));

        $this->assertSame(app(Apps::class)->getId(), $action->apps_id);
        $this->assertSame(0, (int) $action->companies_id);
    }

    public function testCannotUpdateAGlobalAction(): void
    {
        $global = $this->createGlobalAction();

        $response = $this->attemptRename($global->getId());

        $this->assertStringContainsString('is read-only', json_encode($response->json()));
        $this->assertSame($global->name, Action::find($global->getId())->name);
    }

    public function testCannotDeleteAGlobalAction(): void
    {
        $global = $this->createGlobalAction();

        $response = $this->graphQL('
            mutation($id: ID!) {
                deleteAction(id: $id)
            }
        ', ['id' => (string) $global->getId()]);

        $this->assertStringContainsString('is read-only', json_encode($response->json()));
        $this->assertSame(0, (int) Action::find($global->getId())->is_deleted);
    }

    /**
     * A missing action must stay indistinguishable from one owned by another app, so the read-only
     * message can't be used to probe which ids exist elsewhere.
     */
    public function testUpdatingAnotherAppsActionReportsNotFoundRatherThanReadOnly(): void
    {
        $response = $this->attemptRename($this->createForeignAppAction()->getId());

        $body = json_encode($response->json());
        $this->assertStringNotContainsString('is read-only', $body);
        $this->assertStringContainsString('No query results', $body);
    }

    public function testGetActionsDoesNotLeakOtherAppActions(): void
    {
        $otherAppAction = $this->createForeignAppAction();

        $this->queryActionsByName($otherAppAction->name)
            ->assertSuccessful()
            ->assertJsonCount(0, 'data.actionEngineActions.data');
    }

    private function assertActionIsListed(string $name, int|string $expectedId): void
    {
        $this->queryActionsByName($name)
            ->assertSuccessful()
            ->assertJson([
                'data' => [
                    'actionEngineActions' => [
                        'data' => [
                            ['id' => (string) $expectedId],
                        ],
                    ],
                ],
            ]);
    }

    private function queryActionsByName(string $name): TestResponse
    {
        return $this->graphQL('
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
        ]);
    }

    private function attemptRename(int|string $id): TestResponse
    {
        return $this->graphQL('
            mutation($id: ID!, $input: UpdateActionInput!) {
                updateAction(id: $id, input: $input) {
                    id
                }
            }
        ', [
            'id' => (string) $id,
            'input' => ['name' => 'Should Not Apply ' . fake()->uuid()],
        ]);
    }

    private function createActionWithName(string $name): string
    {
        return $this->graphQL('
            mutation($input: ActionInput!) {
                createAction(input: $input) {
                    id
                }
            }
        ', ['input' => ['name' => $name]])
            ->assertSuccessful()
            ->json('data.createAction.id');
    }

    /**
     * Built on the model rather than through createAction: the mutation pegs every row to the acting
     * app, so a global action is only ever placed by the platform.
     */
    private function createGlobalAction(): Action
    {
        return $this->createActionRow(0, 'Global Action ');
    }

    private function createForeignAppAction(): Action
    {
        return $this->createActionRow(app(Apps::class)->getId() + 10000, 'Foreign App Action ');
    }

    private function createActionRow(int $appsId, string $namePrefix): Action
    {
        $action = new Action();
        $action->apps_id = $appsId;
        $action->companies_id = 0;
        $action->users_id = auth()->user()->getId();
        $action->pipelines_id = 0;
        $action->name = $namePrefix . fake()->uuid();
        $action->is_active = true;
        $action->is_published = true;
        $action->saveOrFail();

        return $action;
    }
}
