<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Baka\Support\Str;
use Kanvas\Social\Reactions\Models\Reaction;
use Kanvas\Social\Reactions\Models\UserReaction;
use Kanvas\SystemModules\Models\SystemModules;
use Tests\TestCase;

class ReactionTest extends TestCase
{
    // Reaction
    // UserReaction

    public function testCreateReaction()
    {
        $input = [
        'name' => fake()->name(),
        'icon' => fake()->emoji(),
       ];
        $this->graphQL(/** @lang GRAPHQL */
            '
               mutation createReaction(
                   $input: ReactionInput!
               ) 
               {
                   createReaction(input: $input) {
                       id
                       name
                       icon
                   }
               }
           ',
            [
               'input' => $input,
           ]
        )->assertJson([
           'data' => [
               'createReaction' => $input,
           ],
       ]);
    }

    public function testUpdateReaction()
    {
        $input = [
        'name' => fake()->name(),
        'icon' => fake()->emoji(),
       ];
        $response = $this->graphQL(/** @lang GRAPHQL */
            '
               mutation createReaction(
                   $input: ReactionInput!
               ) 
               {
                   createReaction(input: $input) {
                       id
                       name
                       icon
                   }
               }
           ',
            [
               'input' => $input,
           ]
        );
        $id = $response->json('data.createReaction.id');
        $input = [
            'name' => fake()->name(),
            'icon' => fake()->emoji(),
        ];
        $this->graphQL(/** @lang GRAPHQL */
            '
               mutation updateReaction(
                   $id: ID!
                   $input: ReactionInput!
               ) 
               {
                   updateReaction(id: $id, input: $input) {
                       id
                       name
                       icon
                   }
               }
           ',
            [
               'id' => $id,
               'input' => $input,
           ]
        )->assertJson([
           'data' => [
               'updateReaction' => [
                   'name' => $input['name'],
                   'icon' => $input['icon'],
               ],
           ],
       ]);
    }

    public function testDeleteReaction()
    {
        $input = [
        'name' => fake()->name(),
        'icon' => fake()->emoji(),
       ];
        $response = $this->graphQL(/** @lang GRAPHQL */
            '
               mutation createReaction(
                   $input: ReactionInput!
               ) 
               {
                   createReaction(input: $input) {
                       id
                       name
                       icon
                   }
               }
           ',
            [
               'input' => $input,
           ]
        );
        $id = $response->json('data.createReaction.id');
        $this->graphQL(/** @lang GRAPHQL */
            '
               mutation deleteReaction(
                   $id: ID!
               ) 
               {
                   deleteReaction(id: $id)
               }
           ',
            [
               'id' => $id,
           ]
        )->assertJson([
           'data' => [
               'deleteReaction' => true,
           ],
       ]);
    }

    public function testGetReactions()
    {
        $input = [
            'name' => fake()->name(),
            'icon' => fake()->emoji(),
        ];
        $this->graphQL(/** @lang GRAPHQL */
            '
               mutation createReaction(
                   $input: ReactionInput!
               ) 
               {
                   createReaction(input: $input) {
                       id
                       name
                       icon
                   }
               }
           ',
            [
               'input' => $input,
           ]
        )->assertJson([
           'data' => [
               'createReaction' => $input,
           ], ]);

        $response = $this->graphQL(/** @lang GRAPHQL */
            '
            
            query reactions {
                reactions(
                    orderBy: {column: ID, order: DESC}
                ) {
                    data {
                        id
                        name
                        icon
                    }   
                }
            }'
        );
        $this->assertArrayHasKey('reactions', $response->json('data'));
        $this->assertArrayHasKey('data', $response->json('data.reactions'));
        $this->assertArrayHasKey('id', $response->json('data.reactions.data.0'));
    }

    public function testReactToEntity()
    {
        $systemModule = SystemModules::fromApp()->orderBy('id', 'ASC')->first();
        $reaction = Reaction::fromApp()->orderBy('id', 'DESC')->first();
        $input = [
            'reactions_id' => $reaction->id,
            'entity_id' => Str::uuid(),
            'system_modules_uuid' => $systemModule->uuid,
        ];
        $this->graphQL(/** @lang GRAPHQL */
            '
               mutation reactToEntity(
                   $input: UserReactionInput!
                   ){
                    reactToEntity(input: $input)
                   }
                   ',
            [
                'input' => $input,
            ]
        )->assertJson([
            'data' => [
                'reactToEntity' => true,
            ],
        ]);
    }

    public function testGetUserReaction()
    {
        $systemModule = SystemModules::fromApp()->orderBy('id', 'ASC')->first();
        $reaction = Reaction::fromApp()->orderBy('id', 'DESC')->first();
        $input = [
            'reactions_id' => $reaction->id,
            'entity_id' => Str::uuid(),
            'system_modules_uuid' => $systemModule->uuid,
        ];
        $this->graphQL(/** @lang GRAPHQL */
            '
               mutation reactToEntity(
                   $input: UserReactionInput!
                   ){
                    reactToEntity(input: $input)
                   }
                   ',
            [
                'input' => $input,
            ]
        )->assertJson([
            'data' => [
                'reactToEntity' => true,
            ],
        ]);
        $modelName = str_replace('\\', '\\\\', $systemModule->model_name);
        $this->graphQL(
            /** @lang GRAPHQL */
            '{
                countUserReaction(
                    where: {column: ENTITY_ID, value: "' . $input['entity_id'] . '", operator: EQ,
                    AND: [
                        {column: ENTITY_NAMESPACE, value: "' . $modelName . '", operator: EQ}
                    ]
                }) 
            }
            '
        )->assertJson([
            'data' => [
                'countUserReaction' => 1,
            ],
        ]);
    }
}
