<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

class UsersInteractionsTest extends TestCase
{
    public function testLikeEntity()
    {
        $this->graphQL(
            '
            mutation userLikeEntity($input: UserInteractionInput!) {
                userLikeEntity(input: $input)
            }
            ',
            [
                'input' => [
                    'entity_id' => fake()->uuid(),
                    'entity_namespace' => Lead::class,
                ],
            ]
        )->assertJson([
            'data' => ['userLikeEntity' => true],
        ]);
    }

    public function testUserUnlikeEntity()
    {
        $input = [
            'entity_id' => fake()->uuid(),
            'entity_namespace' => Lead::class,
        ];
        $this->graphQL(
            '
            mutation userLikeEntity($input: UserInteractionInput!) {
                userLikeEntity(input: $input)
            }
            ',
            [
                'input' => $input,
            ]
        )->assertJson([
            'data' => ['userLikeEntity' => true],
        ]);

        $this->graphQL(
            'mutation userUnLikeEntity($input: UserInteractionInput!) {
                userUnLikeEntity(input: $input)
            }',
            [
                'input' => $input,
            ]
        )->assertJson([
             'data' => ['userUnLikeEntity' => true],
         ]);
    }

    public function testDisLikeEntity()
    {
        $input = [
            'entity_id' => fake()->uuid(),
            'entity_namespace' => Lead::class,
        ];

        $this->graphQL(
            '
            mutation userDisLikeEntity($input: UserInteractionInput!) {
                userDisLikeEntity(input: $input)
            }
            ',
            [
                'input' => $input,
            ]
        )->assertJson([
            'data' => ['userDisLikeEntity' => true],
        ]);
    }

    public function testShareUser()
    {
        $user = auth()->user();
        $this->graphQL(
            '
            mutation shareUser($id: ID!) {
                shareUser(id: $id)
            }
            ',
            [
                'id' => $user->getId(),
            ]
        )->assertJson([
            'data' => ['shareUser' => '/' . $user->displayname],
        ]);
    }
}
