<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PipelineTest extends TestCase
{
    public function testGetPipeline(): void
    {
        $this->graphQL('
            query {
                pipelines {
                    data {
                        id
                        name
                    }
                }
            }')->assertOk();
    }

    protected function createPipeline(): TestResponse
    {
        $name = fake()->name();

        $input = [
            'name' => $name,
            'weight' => 0,
            'is_default' => false,
            'stages' => [],
        ];

        return $this->graphQL('
            mutation($input: PipelineInput!) {
                createPipeline(input: $input) {       
                    id         
                    name
                }
            }
        ', [
                'input' => $input,
        ]);
    }

    public function testCreatePipelineWithStages()
    {
        $name = fake()->name();
        $stageName = fake()->name();
        $input = [
            'name' => $name,
            'weight' => 0,
            'is_default' => false,
            'stages' => [
                [
                    'name' => $stageName,
                    'rotting_days' => 1,
                    'weight' => 1,
                ],
            ],
        ];

        $this->graphQL('
            mutation($input: PipelineInput!) {
                createPipeline(input: $input) {       
                    name,
                    stages {
                        name
                    }
                }
            }
        ', [
            'input' => $input,
        ])->assertJson([
            'data' => [
                'createPipeline' => [
                    'name' => $name,
                    'stages' => [
                        [
                            'name' => $stageName,
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testUpdatePipelineWithStages()
    {
        $name = fake()->name();
        $stageName = fake()->name();
        $input = [
            'name' => $name,
            'weight' => 0,
            'is_default' => false,
            'stages' => [
                [
                    'name' => $stageName,
                    'rotting_days' => 1,
                    'weight' => 1,
                ],
            ],
        ];

        $response = $this->graphQL('
            mutation($input: PipelineInput!) {
                createPipeline(input: $input) {   
                    id,    
                    name,
                    stages {
                        id
                        name
                    }
                }
            }
        ', [
            'input' => $input,
        ]);
        $stageId = $response->json('data.createPipeline.stages.0.id');
        $pipelineId = $response->json('data.createPipeline.id');

        $stageNameNew = fake()->name();
        $input['stages'][0]['name'] = $stageNameNew;
        $input['stages'][0]['stages_id'] = $stageId;

        $this->graphQL('
        mutation($id: ID!, $input: PipelineInput!){
            updatePipeline(id: $id, input: $input){
                name,
                stages {
                    id
                    name
                }
            }
        }
        ', [
                    'id' => $pipelineId,
                    'input' => $input,
            ])->assertJson([
                'data' => [
                    'updatePipeline' => [
                        'name' => $name,
                        'stages' => [
                            [
                                'id' => $stageId,
                                'name' => $stageNameNew,
                            ],
                        ],
                    ],
                ],
            ]);
    }

    public function testUpdatePipelineStagesPreservesExistingStageIds()
    {
        $stageName1 = fake()->name();
        $stageName2 = fake()->name();
        $input = [
            'name' => fake()->name(),
            'weight' => 0,
            'is_default' => false,
            'stages' => [
                ['name' => $stageName1, 'rotting_days' => 1, 'weight' => 1],
                ['name' => $stageName2, 'rotting_days' => 2, 'weight' => 2],
            ],
        ];

        $response = $this->graphQL('
            mutation($input: PipelineInput!) {
                createPipeline(input: $input) {
                    id
                    stages { id name }
                }
            }
        ', ['input' => $input]);

        $pipelineId = $response->json('data.createPipeline.id');
        $stage1Id = $response->json('data.createPipeline.stages.0.id');
        $stage2Id = $response->json('data.createPipeline.stages.1.id');

        // Update: keep both stages with updated names, send stages_id to preserve them
        $updatedName1 = fake()->name();
        $updatedName2 = fake()->name();
        $input['stages'] = [
            ['stages_id' => $stage1Id, 'name' => $updatedName1, 'rotting_days' => 1, 'weight' => 1],
            ['stages_id' => $stage2Id, 'name' => $updatedName2, 'rotting_days' => 2, 'weight' => 2],
        ];

        $updateResponse = $this->graphQL('
            mutation($id: ID!, $input: PipelineInput!) {
                updatePipeline(id: $id, input: $input) {
                    id
                    stages { id name }
                }
            }
        ', ['id' => $pipelineId, 'input' => $input])
        ->assertSuccessful();

        $updatedStages = $updateResponse->json('data.updatePipeline.stages');

        $this->assertCount(2, $updatedStages);
        $this->assertEquals($stage1Id, $updatedStages[0]['id'], 'Stage 1 ID must be preserved after update');
        $this->assertEquals($stage2Id, $updatedStages[1]['id'], 'Stage 2 ID must be preserved after update');
        $this->assertEquals($updatedName1, $updatedStages[0]['name']);
        $this->assertEquals($updatedName2, $updatedStages[1]['name']);
    }

    public function testUpdatePipelineRemovesOnlyDeletedStages()
    {
        $input = [
            'name' => fake()->name(),
            'weight' => 0,
            'is_default' => false,
            'stages' => [
                ['name' => fake()->name(), 'rotting_days' => 1, 'weight' => 1],
                ['name' => fake()->name(), 'rotting_days' => 2, 'weight' => 2],
                ['name' => fake()->name(), 'rotting_days' => 3, 'weight' => 3],
            ],
        ];

        $response = $this->graphQL('
            mutation($input: PipelineInput!) {
                createPipeline(input: $input) {
                    id
                    stages { id name }
                }
            }
        ', ['input' => $input]);

        $pipelineId = $response->json('data.createPipeline.id');
        $stage1Id = $response->json('data.createPipeline.stages.0.id');
        $stage2Id = $response->json('data.createPipeline.stages.1.id');

        // Update: keep stage 1, remove stages 2 and 3, add a new stage
        $newStageName = fake()->name();
        $input['stages'] = [
            ['stages_id' => $stage1Id, 'name' => $input['stages'][0]['name'], 'rotting_days' => 1, 'weight' => 1],
            ['name' => $newStageName, 'rotting_days' => 5, 'weight' => 4],
        ];

        $updateResponse = $this->graphQL('
            mutation($id: ID!, $input: PipelineInput!) {
                updatePipeline(id: $id, input: $input) {
                    id
                    stages { id name }
                }
            }
        ', ['id' => $pipelineId, 'input' => $input])
        ->assertSuccessful();

        $stages = $updateResponse->json('data.updatePipeline.stages');

        // Should have exactly 2 stages
        $this->assertCount(2, $stages);

        // Stage 1 should keep its original ID
        $this->assertEquals($stage1Id, $stages[0]['id']);

        // Stage 2 (removed) should not exist
        $stageIds = array_column($stages, 'id');
        $this->assertNotContains($stage2Id, $stageIds);

        // New stage should exist
        $this->assertEquals($newStageName, $stages[1]['name']);
    }

    public function testCreatePipeline()
    {
        $pipeline = $this->createPipeline();
        $data = $pipeline->json('data.createPipeline');
        $name = $data['name'];

        $pipeline->assertJson([
             'data' => [
                 'createPipeline' => [
                     'name' => $name,
                 ],
             ],
         ]);
    }

    public function testUpdatePipeline()
    {
        $pipeline = $this->createPipeline()->json('data.createPipeline');

        $newName = fake()->name();

        $input = [
             'name' => $newName,
             'weight' => 0,
             'is_default' => false,
             'stages' => [],
        ];

        $this->graphQL('
            mutation($id: ID!, $input: PipelineInput!){
                updatePipeline(id: $id, input: $input){
                    id,
                    name,
                    slug
                }
            }
            ', [
                'id' => $pipeline['id'],
                'input' => $input,
        ])->assertJson([
                'data' => [
                    'updatePipeline' => [
                        'name' => $newName,
                    ],
                ],
            ]);
    }

    public function testDeletePipeline()
    {
        $pipeline = $this->createPipeline()->json('data.createPipeline');

        $this->graphQL('
            mutation($id: ID!){
                deletePipeline(id: $id)
            }
            ', [
                'id' => $pipeline['id'],
        ])->assertJson([
                'data' => [
                    'deletePipeline' => true,
                ],
            ]);
    }

    public function testRestorePipeline()
    {
        $pipeline = $this->createPipeline()->json('data.createPipeline');

        $this->graphQL('
            mutation($id: ID!){
                deletePipeline(id: $id)
            }
            ', [
                'id' => $pipeline['id'],
        ])->assertJson([
                'data' => [
                    'deletePipeline' => true,
                ],
            ]);

        $this->graphQL('
            mutation($id: ID!){
                restorePipeline(id: $id)
            }
            ', [
                'id' => $pipeline['id'],
        ])->assertJson([
                'data' => [
                    'restorePipeline' => true,
                ],
            ]);
    }

    protected function createPipelineStage(): TestResponse
    {
        $pipeline = $this->createPipeline()->json('data.createPipeline');

        $name = fake()->name();

        $input = [
            'pipeline_id' => $pipeline['id'],
            'name' => $name,
            'weight' => 0,
            'rotting_days' => 0,
        ];

        return $this->graphQL('
            mutation($input: PipelineStageInput!){
                createPipelineStage(input: $input){
                    id,
                    name
                    pipeline{
                        id
                    }
                }
            }
            ', [
                'input' => $input,
            ]);
    }

    public function testCreatePipelineStage()
    {
        $stage = $this->createPipelineStage();
        $data = $stage->json('data.createPipelineStage');
        $name = $data['name'];

        $stage->assertJson([
                'data' => [
                    'createPipelineStage' => [
                        'name' => $name,
                    ],
                ],
            ]);

        $pipeline = $this->graphQL('
            query($id: Mixed!){
                pipelines(where: {column: ID, operator: EQ, value: $id}){
                    data{
                    id,
                    name,
                    stages{
                            id,
                            name
                        
                    }
                }
            }}
            ', [
                'id' => $data['pipeline']['id'],
            ])->assertSee($name);
    }

    public function testUpdatePipelineStage()
    {
        $stage = $this->createPipelineStage()->json('data.createPipelineStage');

        $newName = fake()->name();

        $input = [
            'pipeline_id' => $stage['pipeline']['id'],
            'name' => $newName,
            'weight' => 0,
            'rotting_days' => 0,
        ];

        $this->graphQL('
            mutation($id: ID!, $input: PipelineStageInput!){
                updatePipelineStage(id: $id, input: $input){
                    id,
                    name
                }
            }
            ', [
                'id' => $stage['id'],
                'input' => $input,
            ])->assertJson([
                'data' => [
                    'updatePipelineStage' => [
                        'name' => $newName,
                    ],
                ],
            ]);
    }

    public function testDeletePipelineStage()
    {
        $stage = $this->createPipelineStage()->json('data.createPipelineStage');

        $this->graphQL('
            mutation($id: ID!){
                deletePipelineStage(id: $id)
            }
            ', [
                'id' => $stage['id'],
            ])->assertJson([
                'data' => [
                    'deletePipelineStage' => true,
                ],
            ]);
    }

    public function testRestorePipelineStage()
    {
        $stage = $this->createPipelineStage()->json('data.createPipelineStage');

        $this->graphQL('
            mutation($id: ID!){
                deletePipelineStage(id: $id)
            }
            ', [
                'id' => $stage['id'],
            ])->assertJson([
                'data' => [
                    'deletePipelineStage' => true,
                ],
            ]);

        $this->graphQL('
            mutation($id: ID!){
                restorePipelineStage(id: $id)
            }
            ', [
                'id' => $stage['id'],
            ])->assertJson([
                'data' => [
                    'restorePipelineStage' => true,
                ],
            ]);
    }
}
