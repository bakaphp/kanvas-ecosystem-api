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
                    'percentage' => 0
                ]
            ]
        ];

        $this->graphQL('
            mutation($input: CreateRotationInput!) {
                createRotation(input: $input) {
                    name
                }
            }', [
                'input' => $input
            ])
            ->assertJson([
                'data' => [
                    'createRotation' => [
                        'name' => 'Test Rotation'
                    ]
                ]
            ]);
    }
}
