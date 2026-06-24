<?php

declare(strict_types=1);

namespace Tests\GraphQL\ActionEngine;

use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Tests\TestCase;

class CompanyActionTest extends TestCase
{
    private function createGlobalAction(): array
    {
        // actions.slug is globally unique and these tests commit (no DatabaseTransactions),
        // so the name must be unique per run — fake()->word() draws from a small pool and
        // eventually collides across accumulated rows.
        $input = [
            'name' => 'Global Action ' . uniqid(),
            'description' => 'Global action for company action test',
            'is_active' => true,
            'is_published' => true,
        ];

        $response = $this->graphQL('
            mutation($input: ActionInput!) {
                createAction(input: $input) {
                    id
                    name
                    description
                }
            }
        ', ['input' => $input])->assertSuccessful();

        return $response->json('data.createAction');
    }

    private function createCompanyAction(?array $overrides = []): array
    {
        $globalAction = $this->createGlobalAction();

        $input = array_merge([
            'actions_id' => $globalAction['id'],
            'name' => 'Company Action ' . fake()->word(),
        ], $overrides);

        $response = $this->graphQL('
            mutation($input: CreateCompanyActionInput!) {
                createCompanyAction(input: $input) {
                    id
                    name
                    description
                    is_active
                    is_published
                    action {
                        id
                        name
                    }
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
        ', ['input' => $input])->assertSuccessful();

        return [
            'globalAction' => $globalAction,
            'companyAction' => $response->json('data.createCompanyAction'),
        ];
    }

    public function testCreateCompanyAction(): void
    {
        $result = $this->createCompanyAction();
        $globalAction = $result['globalAction'];
        $companyAction = $result['companyAction'];

        $this->assertNotEmpty($companyAction['name']);
        $this->assertFalse($companyAction['is_active']);
        $this->assertFalse($companyAction['is_published']);
        $this->assertEquals($globalAction['id'], $companyAction['action']['id']);
        $this->assertNotNull($companyAction['pipeline']);
        $this->assertCount(3, $companyAction['pipeline']['stages']);
    }

    public function testCreateCompanyActionWithLocalAction(): void
    {
        // An action created via the createAction mutation lands with apps_id = current app
        // (see CreateActionAction::execute). That's the "local action" path.
        $localAction = $this->createGlobalAction();

        $this->assertEquals(
            app(Apps::class)->getId(),
            Action::find($localAction['id'])->apps_id,
            'Sanity check: createAction should produce an action scoped to the current app.',
        );

        $response = $this->graphQL('
            mutation($input: CreateCompanyActionInput!) {
                createCompanyAction(input: $input) {
                    id
                    name
                    action {
                        id
                    }
                }
            }
        ', [
            'input' => [
                'actions_id' => $localAction['id'],
                'name' => 'Local Backed Company Action ' . fake()->word(),
            ],
        ])->assertSuccessful();

        $companyAction = $response->json('data.createCompanyAction');
        $this->assertEquals($localAction['id'], $companyAction['action']['id']);
    }

    public function testCreateCompanyActionWithGlobalAction(): void
    {
        // A true global action has apps_id = 0 (AppEnums::LEGACY_APP_ID). Models created via
        // CreateActionAction always set apps_id to the current app, so we down-shift one
        // here to simulate a globally-shared action seeded into the action_engine DB.
        $createdAction = $this->createGlobalAction();
        $globalAction = Action::find($createdAction['id']);
        $globalAction->apps_id = AppEnums::LEGACY_APP_ID->getValue();
        $globalAction->saveOrFail();

        $this->assertSame(0, (int) $globalAction->fresh()->apps_id);

        $response = $this->graphQL('
            mutation($input: CreateCompanyActionInput!) {
                createCompanyAction(input: $input) {
                    id
                    name
                    action {
                        id
                    }
                }
            }
        ', [
            'input' => [
                'actions_id' => $globalAction->id,
                'name' => 'Global Backed Company Action ' . fake()->word(),
            ],
        ])->assertSuccessful();

        $companyAction = $response->json('data.createCompanyAction');
        $this->assertEquals($globalAction->id, $companyAction['action']['id']);
    }

    public function testCreateCompanyActionWithCustomFields(): void
    {
        $globalAction = $this->createGlobalAction();

        $input = [
            'actions_id' => $globalAction['id'],
            'name' => 'Custom Company Action ' . fake()->word(),
            'description' => 'Custom description',
            'is_active' => true,
            'is_published' => true,
        ];

        $response = $this->graphQL('
            mutation($input: CreateCompanyActionInput!) {
                createCompanyAction(input: $input) {
                    id
                    name
                    description
                    is_active
                    is_published
                    action {
                        id
                    }
                    pipeline {
                        id
                        stages {
                            id
                            name
                        }
                    }
                }
            }
        ', ['input' => $input])->assertSuccessful();

        $companyAction = $response->json('data.createCompanyAction');
        $this->assertEquals($input['name'], $companyAction['name']);
        $this->assertEquals('Custom description', $companyAction['description']);
        $this->assertTrue($companyAction['is_active']);
        $this->assertTrue($companyAction['is_published']);
        $this->assertCount(3, $companyAction['pipeline']['stages']);
    }

    public function testUpdateCompanyAction(): void
    {
        $result = $this->createCompanyAction();
        $companyActionId = $result['companyAction']['id'];

        $updateInput = [
            'name' => 'Updated Company Action ' . fake()->word(),
            'description' => 'Updated description',
            'is_active' => true,
        ];

        $this->graphQL('
            mutation($id: ID!, $input: UpdateCompanyActionInput!) {
                updateCompanyAction(id: $id, input: $input) {
                    id
                    name
                    description
                    is_active
                }
            }
        ', [
            'id' => $companyActionId,
            'input' => $updateInput,
        ])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateCompanyAction' => [
                    'id' => $companyActionId,
                    'name' => $updateInput['name'],
                    'description' => 'Updated description',
                    'is_active' => true,
                ],
            ],
        ]);
    }

    public function testDeleteCompanyAction(): void
    {
        $result = $this->createCompanyAction();
        $companyActionId = $result['companyAction']['id'];

        $this->graphQL('
            mutation($id: ID!) {
                deleteCompanyAction(id: $id)
            }
        ', ['id' => $companyActionId])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'deleteCompanyAction' => true,
            ],
        ]);
    }

    public function testGetCompanyActions(): void
    {
        $this->graphQL('
            query {
                companyActions {
                    data {
                        id
                        name
                        description
                        form_config
                        status
                        is_active
                        is_published
                        weight
                        pipeline {
                            id
                        }
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
                'companyActions' => [
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'description',
                            'form_config',
                            'status',
                            'is_active',
                            'is_published',
                            'weight',
                        ],
                    ],
                ],
            ],
        ]);
    }
}
