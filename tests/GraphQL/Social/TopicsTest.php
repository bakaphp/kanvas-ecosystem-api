<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Tests\TestCase;

class TopicsTest extends TestCase
{
    public function testCreateTopic()
    {
        $input = [
            'name' => fake()->name(),
            'weight' => fake()->numberBetween(0, 1),
            'is_feature' => fake()->numberBetween(0, 1),
            'status' => fake()->boolean(),
        ];

        $this->graphQL(/** @lang GRAPHQL */
            '
                mutation createTopic(
                    $input: TopicInput!
                ) 
                {
                    createTopic(input: $input) {
                        name,
                        weight,
                        is_feature,
                        status
                    }
                }
            ',
            [
                'input' => $input,
            ]
        )->assertJson([
            'data' => [
                'createTopic' => $input,
            ],
        ]);
    }

    public function testUpdateTopic()
    {
        $input = [
            'name' => fake()->name(),
            'weight' => fake()->numberBetween(0, 1),
            'is_feature' => fake()->numberBetween(0, 1),
            'status' => fake()->boolean(),
        ];

        $response = $this->graphQL(/** @lang GRAPHQL */
            '
                mutation createTopic(
                    $input: TopicInput!
                ) 
                {
                    createTopic(input: $input) {
                        id
                        name
                        weight
                        is_feature
                        status
                    }
                }
            ',
            [
                'input' => $input,
            ]
        );

        $topic = $response->json('data.createTopic');
        $input = [
            'name' => fake()->name(),
            'weight' => fake()->numberBetween(0, 1),
            'is_feature' => fake()->numberBetween(0, 1),
            'status' => fake()->boolean(),
        ];
        $this->graphQL(/** @lang GRAPHQL */
            '
                mutation updateTopic(
                    $id: ID!
                    $input: TopicInput!
                ) 
                {
                    updateTopic(id: $id, input: $input) {
                        name
                        weight
                        is_feature
                        status
                    }
                }
             ',
            [
                    'id' => $topic['id'],
                    'input' => $input,
                ]
        )->assertJson([
            'data' => [
                'updateTopic' => $input,
            ],
        ]);
    }

    public function testGetTopic()
    {
        $input = [
            'name' => fake()->name(),
            'weight' => fake()->numberBetween(0, 1),
            'is_feature' => fake()->numberBetween(0, 1),
            'status' => fake()->boolean(),
        ];

        $response = $this->graphQL(/** @lang GRAPHQL */
            '
                mutation createTopic(
                    $input: TopicInput!
                ) 
                {
                    createTopic(input: $input) {
                        id
                        name
                        weight
                        is_feature
                        status
                    }
                }
            ',
            [
                'input' => $input,
            ]
        );

        $this->graphQL(
            '
            {
             topics {
                data {
                    id
                    name
                    weight
                    is_feature
                    status
                }
             }
            }    
            '
        )->assertJsonFragment([
                'name' => $input['name'],
                'weight' => $input['weight'],
                'is_feature' => $input['is_feature'],
                'status' => $input['status'],
            ]);
    }
}
