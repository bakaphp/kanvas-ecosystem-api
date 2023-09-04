<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

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

    public function testCreatePipeline()
    {
        $name = fake()->name();

        $input = [
            'name' => $name,
            'weight' => 0,
            'is_default' => false,
            'stages' => [],
        ];

        $this->graphQL('
        mutation($input: PipelineInput!) {
            createPipeline(input: $input) {                
                name
            }
        }
    ', [
            'input' => $input,
        ])->assertJson([
            'data' => [
                'createPipeline' => [
                    'name' => $name,
                ],
            ],
        ]);
    }
}
