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
