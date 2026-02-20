<?php

declare(strict_types=1);

namespace Tests\GraphQL\ActionEngine;

use Tests\TestCase;

class PipelineTest extends TestCase
{
    private function createPipeline(?array $overrides = []): array
    {
        $input = array_merge([
            'name' => 'Pipeline ' . fake()->word(),
        ], $overrides);

        $response = $this->graphQL('
            mutation($input: CreatePipelineInput!) {
                createActionPipeline(input: $input) {
                    id
                    name
                    slug
                    weight
                    is_default
                    stages {
                        id
                        name
                        weight
                    }
                }
            }
        ', ['input' => $input])->assertSuccessful();

        return $response->json('data.createActionPipeline');
    }

    private function createPipelineStage(int $pipelineId, ?array $overrides = []): array
    {
        $input = array_merge([
            'pipelines_id' => $pipelineId,
            'name' => 'Stage ' . fake()->word(),
        ], $overrides);

        $response = $this->graphQL('
            mutation($input: CreatePipelineStageInput!) {
                createActionPipelineStage(input: $input) {
                    id
                    name
                    has_rotting_days
                    rotting_days
                    weight
                    pipeline {
                        id
                        name
                    }
                }
            }
        ', ['input' => $input])->assertSuccessful();

        return $response->json('data.createActionPipelineStage');
    }

    public function testGetActionPipelines(): void
    {
        $this->graphQL('
            query {
                actionPipelines {
                    data {
                        id
                        name
                        slug
                        weight
                        is_default
                        created_at
                        updated_at
                    }
                }
            }
        ')
        ->assertSuccessful()
        ->assertJsonStructure([
            'data' => [
                'actionPipelines' => [
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'slug',
                            'weight',
                            'is_default',
                            'created_at',
                            'updated_at',
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testCreateActionPipeline(): void
    {
        $pipeline = $this->createPipeline();

        $this->assertNotEmpty($pipeline['name']);
        $this->assertNotEmpty($pipeline['slug']);
        $this->assertCount(3, $pipeline['stages']);
    }

    public function testUpdateActionPipeline(): void
    {
        $pipeline = $this->createPipeline();

        $updateInput = [
            'name' => 'Updated Pipeline ' . fake()->word(),
            'weight' => 10,
        ];

        $this->graphQL('
            mutation($id: ID!, $input: UpdatePipelineInput!) {
                updateActionPipeline(id: $id, input: $input) {
                    id
                    name
                    weight
                }
            }
        ', [
            'id' => $pipeline['id'],
            'input' => $updateInput,
        ])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateActionPipeline' => [
                    'id' => $pipeline['id'],
                    'name' => $updateInput['name'],
                    'weight' => 10,
                ],
            ],
        ]);
    }

    public function testDeleteActionPipeline(): void
    {
        $pipeline = $this->createPipeline();

        $this->graphQL('
            mutation($id: ID!) {
                deleteActionPipeline(id: $id)
            }
        ', ['id' => $pipeline['id']])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'deleteActionPipeline' => true,
            ],
        ]);
    }

    public function testCannotDeletePipelineInUse(): void
    {
        // Create an action which auto-creates a pipeline
        $actionInput = [
            'name' => 'Action For Pipeline Test ' . fake()->word(),
            'is_active' => true,
            'is_published' => true,
        ];

        $actionResponse = $this->graphQL('
            mutation($input: ActionInput!) {
                createAction(input: $input) {
                    id
                    name
                }
            }
        ', ['input' => $actionInput])->assertSuccessful();

        $action = $actionResponse->json('data.createAction');

        // Create a company action which creates its own pipeline
        $companyActionInput = [
            'actions_id' => $action['id'],
            'name' => 'Company Action ' . fake()->word(),
        ];

        $companyActionResponse = $this->graphQL('
            mutation($input: CreateCompanyActionInput!) {
                createCompanyAction(input: $input) {
                    id
                    pipeline {
                        id
                    }
                }
            }
        ', ['input' => $companyActionInput])->assertSuccessful();

        $pipelineId = $companyActionResponse->json('data.createCompanyAction.pipeline.id');

        // Try to delete the pipeline in use
        $response = $this->graphQL('
            mutation($id: ID!) {
                deleteActionPipeline(id: $id)
            }
        ', ['id' => $pipelineId]);

        $this->assertStringContainsString(
            'Cannot delete pipeline that is in use by company actions',
            json_encode($response->json())
        );
    }

    public function testCreateActionPipelineStage(): void
    {
        $pipeline = $this->createPipeline();
        $stage = $this->createPipelineStage((int) $pipeline['id'], [
            'name' => 'Custom Stage',
            'weight' => 5,
            'has_rotting_days' => 1,
            'rotting_days' => 30,
        ]);

        $this->assertEquals('Custom Stage', $stage['name']);
        $this->assertEquals(5, $stage['weight']);
        $this->assertEquals(1, $stage['has_rotting_days']);
        $this->assertEquals(30, $stage['rotting_days']);
        $this->assertEquals($pipeline['id'], $stage['pipeline']['id']);
    }

    public function testUpdateActionPipelineStage(): void
    {
        $pipeline = $this->createPipeline();
        $stage = $this->createPipelineStage((int) $pipeline['id']);

        $updateInput = [
            'name' => 'Updated Stage ' . fake()->word(),
            'weight' => 99,
        ];

        $this->graphQL('
            mutation($id: ID!, $input: UpdatePipelineStageInput!) {
                updateActionPipelineStage(id: $id, input: $input) {
                    id
                    name
                    weight
                }
            }
        ', [
            'id' => $stage['id'],
            'input' => $updateInput,
        ])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateActionPipelineStage' => [
                    'id' => $stage['id'],
                    'name' => $updateInput['name'],
                    'weight' => 99,
                ],
            ],
        ]);
    }

    public function testDeleteActionPipelineStage(): void
    {
        $pipeline = $this->createPipeline();
        $stage = $this->createPipelineStage((int) $pipeline['id']);

        $this->graphQL('
            mutation($id: ID!) {
                deleteActionPipelineStage(id: $id)
            }
        ', ['id' => $stage['id']])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'deleteActionPipelineStage' => true,
            ],
        ]);
    }
}
