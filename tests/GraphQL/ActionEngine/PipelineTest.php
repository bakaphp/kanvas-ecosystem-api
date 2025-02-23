<?php

declare(strict_types=1);

namespace Tests\GraphQL\ActionEngine;

use Tests\TestCase;

class PipelineTest extends TestCase
{
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
}
