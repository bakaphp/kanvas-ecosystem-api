<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Tests\TestCase;

class LeadRotationTest extends TestCase
{
    public function testCreateLeadRotation(): void
    {
        $input = [
            'name'                  => fake()->word,
            'leads_rotations_email' => fake()->email,
            'hits'                  => fake()->numberBetween(1, 100),
        ];

        $this->graphQL(
            '
            mutation createLeadRotation($input: LeadRotationInput!) {
                createLeadRotation(input: $input){
                    name,
                    leads_rotations_email,
                    hits,
                }
            }
            ',
            [
                'input' => $input,
            ]
        )->assertJson([
            'data' => [
                'createLeadRotation' => [
                    'name'                  => $input['name'],
                    'leads_rotations_email' => $input['leads_rotations_email'],
                    'hits'                  => $input['hits'],
                ],
            ],
        ]);
    }

    public function testUpdateLeadRotation(): void
    {
        $input = [
            'name'                  => fake()->word,
            'leads_rotations_email' => fake()->email,
            'hits'                  => fake()->numberBetween(1, 100),
        ];

        $response = $this->graphQL(
            '
            mutation createLeadRotation($input: LeadRotationInput!) {
                createLeadRotation(input: $input){
                    id
                }
            }
            ',
            [
                'input' => $input,
            ]
        );
        $id = $response->json('data.createLeadRotation.id');
        $input = [
            'name'                  => fake()->word,
            'leads_rotations_email' => fake()->email,
            'hits'                  => fake()->numberBetween(1, 100),
            'agents'                => [
                [
                    'users_id' => auth()->user()->getId(),
                    'phone'    => fake()->phoneNumber,
                    'percent'  => 100,
                ],
            ],
        ];
        $this->graphQL(
            '
            mutation updateLeadRotation($id: ID!, $input: LeadRotationInput!){
                updateLeadRotation(id: $id, input: $input){
                    name
                }
            }
        ',
            [
                'id'    => $id,
                'input' => $input,
            ]
        )->assertJson([
            'data' => [
                'updateLeadRotation' => [
                    'name' => $input['name'],
                ],
            ],
        ]);
    }

    public function testDeleteLeadRotation()
    {
        $input = [
            'name'                  => fake()->word,
            'leads_rotations_email' => fake()->email,
            'hits'                  => fake()->numberBetween(1, 100),
        ];

        $response = $this->graphQL(
            '
            mutation createLeadRotation($input: LeadRotationInput!) {
                createLeadRotation(input: $input){
                    id
                }
            }
            ',
            [
                'input' => $input,
            ]
        );
        $id = $response->json('data.createLeadRotation.id');
        $this->graphQL('
            mutation deleteLeadRotation($id: ID!){
                deleteLeadRotation(id: $id)
            }
        ', [
            'id' => $id,
        ])->assertJson([
            'data' => [
                'deleteLeadRotation' => true,
            ],
        ]);
    }

    public function testGetLeadRotation(): void
    {
        $input = [
            'name'                  => fake()->word,
            'leads_rotations_email' => fake()->email,
            'hits'                  => fake()->numberBetween(1, 100),
            'agents'                => [
                [
                    'users_id' => auth()->user()->getId(),
                    'phone'    => fake()->phoneNumber,
                    'percent'  => 10,
                    'hits'     => 0,
                ],
            ],
        ];

        $response = $this->graphQL(
            '
            mutation createLeadRotation($input: LeadRotationInput!) {
                createLeadRotation(input: $input){
                    name,
                    leads_rotations_email,
                    hits,
                    company {
                        id
                    }

                }
            }
            ',
            [
                'input' => $input,
            ]
        )->assertJson([
            'data' => [
                'createLeadRotation' => [
                    'name'                  => $input['name'],
                    'leads_rotations_email' => $input['leads_rotations_email'],
                    'hits'                  => $input['hits'],
                    'company'               => [
                        'id' => auth()->user()->getCurrentCompany()->id,
                    ],
                ],
            ],
        ]);

        $response = $this->graphQL(
            '
            query 
            {
                leadsRotations{
                    data {
                        id
                        name
                        leads_rotations_email
                        hits, 
                        agents {
                            user {
                                id
                            }
                        }
                    }
                }
            }
            '
        );
        $response->assertJson([
            'data' => [
                'leadsRotations' => [
                    'data' => [
                        [
                            'name'                  => $input['name'],
                            'leads_rotations_email' => $input['leads_rotations_email'],
                            'hits'                  => $input['hits'],
                            'agents'                => [
                                [
                                    'user' => [
                                        'id' => $input['agents'][0]['users_id'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
