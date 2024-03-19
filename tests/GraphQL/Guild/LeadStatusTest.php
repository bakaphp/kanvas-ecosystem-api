<?php

namespace Tests\GraphQL\Guild;

use Tests\TestCase;

class LeadStatusTest extends TestCase
{
    public function testCreateLeadStatus(): void
    {
        $input = [
            'name' => fake()->word,
            'is_default' => (int)fake()->boolean,
        ];

        $this->graphQL(
            '
            mutation createLeadStatus($input: LeadStatusInput!) {
                createLeadStatus(input: $input){
                    name,
                    is_default,
                }
            }
            ',
            [
                'input' => $input,
            ]
        )->assertJson([
            'data' => [
                'createLeadStatus' => [
                    'name' => $input['name'],
                    'is_default' => $input['is_default'],
                ],
            ],
        ]);
    }

    public function testUpdateLeadStatus(): void
    {
        $input = [
            'name' => fake()->word,
            'is_default' => (int)fake()->boolean,
        ];
        $response = $this->graphQL(
            '
            mutation createLeadStatus($input: LeadStatusInput!) {
                createLeadStatus(input: $input){
                    id,
                    name,
                    is_default,
                }
            }
            ',
            [
                'input' => $input,
            ]
        );

        $id = $response->json('data.createLeadStatus.id');
        $input = [
            'name' => fake()->word,
            'is_default' => (int)fake()->boolean,
        ];
        $this->graphQL(
            '
            mutation updateLeadStatus($id: ID!, $input: LeadStatusInput!) {
                updateLeadStatus(id: $id, input: $input){
                    name,
                    is_default,
                }
            }
            ',
            [
                'id' => $id,
                'input' => $input,
            ]
        )->assertJson([
            'data' => [
                'updateLeadStatus' => [
                    'name' => $input['name'],
                    'is_default' => $input['is_default'],
                ],
            ],
        ]);
    }

    public function testDeleteLeadStatus(): void
    {
        $input = [
            'name' => fake()->word,
            'is_default' => (int)fake()->boolean,
        ];
        $response = $this->graphQL(
            '
            mutation createLeadStatus($input: LeadStatusInput!) {
                createLeadStatus(input: $input){
                    id,
                    name,
                    is_default,
                }
            }
            ',
            [
                'input' => $input,
            ]
        );

        $id = $response->json('data.createLeadStatus.id');
        $this->graphQL(
            '
            mutation deleteLeadStatus($id: ID!) {
                deleteLeadStatus(id: $id)
            }
            ',
            [
                'id' => $id,
            ]
        )->assertJson([
            'data' => [
                'deleteLeadStatus' => true,
            ],
        ]);
    }

    public function testLeadStatus(): void
    {
        $response = $this->graphQL(
            '
                {
                    leadStatuses {
                        data {
                            id,
                            name,
                            is_default,
                        }
                    }
                }   
            '
        )->assertJsonStructure([
            'data' => [
                'leadStatuses' => [
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'is_default',
                        ],
                    ],
                ],
            ],
        ]);
    }
}
