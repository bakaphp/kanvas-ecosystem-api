<?php

declare(strict_types=1);

namespace Tests\GraphQL\Guild;

use Tests\TestCase;

class RotationTest extends TestCase
{
    public function testCreateRotation(): void
    {
        $input = [
            'name' => 'Test Rotation',
            'users' => [
                [
                    'user_id' => auth()->user()->getId(),
                    'name' => fake()->firstName(),
                    'email' => fake()->email,
                    'percentage' => 0,
                ],
            ],
        ];

        $this->graphQL('
            mutation($input: CreateLeadRotationInput!) {
                createLeadRotation(input: $input) {
                    name
                }
            }', [
                'input' => $input,
            ])
            ->assertJson([
                'data' => [
                    'createLeadRotation' => [
                        'name' => 'Test Rotation',
                    ],
                ],
            ]);
    }

    public function testGetRotations(): void
    {
        $input = [
            'name' => 'Test Rotation',
            'users' => [
        [
            'user_id' => auth()->user()->getId(),
            'name' => fake()->firstName(),
            'email' => fake()->email,
            'percentage' => 0,
        ],
            ],
        ];

        $this->graphQL('
            mutation($input: CreateLeadRotationInput!) {
                createLeadRotation(input: $input) {
                    name
                }
            }', [
                'input' => $input,
            ]);

        $response = $this->graphQL(
            '
                query {
                    leadsRotations {
                        data {
                            name,
                            users {
                                email
                            }
                        }
                    }
                }
            '
        );
        $response->assertJsonStructure([
            'data' => [
                'leadsRotations' => [
                    'data' => [
                        '*' => [
                            'name',
                            'users' => [
                                '*' => [
                                    'email',
                                ],
                            ],
                        ],
                ],
            ],
            ],
        ]);
    }

    public function testUpdateRotations(): void
    {
        $input = [
            'name' => 'Test Rotation',
            'users' => [
        [
            'user_id' => auth()->user()->getId(),
            'name' => fake()->firstName(),
            'email' => fake()->email,
            'percentage' => 0,
        ],
            ],
        ];

        $response = $this->graphQL('
            mutation($input: CreateLeadRotationInput!) {
                createLeadRotation(input: $input) {
                    id
                }
            }', [
                'input' => $input,
            ]);
        $id = $response->json('data.createLeadRotation.id');
        $input = [
            'id' => $id,
            'name' => 'Test Rotation Updated',
        ];
        $response = $this->graphQL(
            '
                mutation($input: UpdateLeadRotationInput!) {
                    updateLeadRotation(input: $input) {
                        name
                    }
                }',
            [
                'input' => $input,
            ]
        );
        $response->assertJsonStructure([
            'data' => [
                'updateLeadRotation' => [
                    'name',
                ],
            ],
        ]);
    }

    public function testDeleteRotation(): void
    {
        $input = [
            'name' => 'Test Rotation',
            'users' => [
        [
            'user_id' => auth()->user()->getId(),
            'name' => fake()->firstName(),
            'email' => fake()->email,
            'percentage' => 0,
        ],
            ],
        ];

        $response = $this->graphQL('
            mutation($input: CreateLeadRotationInput!) {
                createLeadRotation(input: $input) {
                    id
                }
            }', [
                'input' => $input,
            ]);
        $id = $response->json('data.createLeadRotation.id');
        $this->graphQL(
            '
                mutation($id: ID!) {
                    deleteLeadRotation(id: $id)
                }',
            [
                'id' => $id,
            ]
        )->assertJson([
            'data' => [
                'deleteLeadRotation' => true,
            ],
        ]);
    }
}
