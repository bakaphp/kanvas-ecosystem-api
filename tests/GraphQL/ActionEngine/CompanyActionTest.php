<?php

declare(strict_types=1);

namespace Tests\GraphQL\ActionEngine;

use Tests\TestCase;

class CompanyActionTest extends TestCase
{
    private function createGlobalAction(): array
    {
        $input = [
            'name' => 'Global Action ' . fake()->word(),
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
