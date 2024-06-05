<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Baka\Support\Str;
use Tests\TestCase;

class TagsTest extends TestCase
{
    public function testCreateTag()
    {
        $input = [
            'name' => fake()->name(),
            'slug' => Str::slug(fake()->name()),
            'weight' => random_int(1, 100),
        ];
        $this->graphQL(/** @lang GRAPHQL */
            '
                mutation createTag(
                    $input: TagInput!
                ) 
                {
                    createTag(input: $input) {
                        id
                        name
                        slug
                        weight
                    }
                }
            ',
            [
                'input' => $input,
            ]
        )->assertJson([
            'data' => [
                'createTag' => $input,
            ],
        ]);
    }

    public function testUpdateTag()
    {
        $input = [
             'name' => fake()->name(),
             'slug' => Str::slug(fake()->name()),
             'weight' => random_int(1, 100),
         ];
        $response = $this->graphQL(/** @lang GRAPHQL */
            '
                mutation createTag(
                    $input: TagInput!
                ) 
                {
                    createTag(input: $input) {
                        id
                        name
                        slug
                        weight
                    }
                }
            ',
            [
               'input' => $input,
            ]
        );
        $tag = $response->json('data.createTag');
        $input['name'] = fake()->name();
        $input['slug'] = Str::slug(fake()->name());
        $input['weight'] = random_int(1, 100);
        $this->graphQL(/** @lang GRAPHQL */
            '
                mutation updateTag(
                    $id: ID!
                    $input: TagInput!
                ) 
                {
                    updateTag(id: $id, input: $input) {
                        id
                        name
                        slug
                        weight
                    }
                }
            ',
            [
                'id' => $tag['id'],
                'input' => $input,
            ]
        )->assertJson([
            'data' => [
                'updateTag' => $input,
            ],
        ]);
    }

    public function testDeleteTag()
    {
        $input = [
            'name' => fake()->name(),
            'slug' => Str::slug(fake()->name()),
            'weight' => random_int(1, 100),
        ];
        $response = $this->graphQL(/** @lang GRAPHQL */
            '
                mutation createTag(
                    $input: TagInput!
                ) 
                {
                    createTag(input: $input) {
                        id
                        name
                        slug
                        weight
                    }
                }
            ',
            [
                'input' => $input,
            ]
        );
        $tag = $response->json('data.createTag');
        $this->graphQL(/** @lang GRAPHQL */
            '
                mutation deleteTag(
                    $id: ID!
                ) 
                {
                    deleteTag(id: $id)
                }
            ',
            [
                'id' => $tag['id'],
            ]
        )->assertJson([
            'data' => [
                'deleteTag' => true,
            ],
        ]);
    }

    public function testFollowTag()
    {
        $input = [
            'name' => fake()->name(),
            'slug' => Str::slug(fake()->name()),
            'weight' => random_int(1, 100),
        ];
        $response = $this->graphQL(/** @lang GRAPHQL */
            '
                mutation createTag(
                    $input: TagInput!
                ) 
                {
                    createTag(input: $input) {
                        id
                        name
                        slug
                        weight
                    }
                }
            ',
            [
                'input' => $input,
            ]
        );
        $tag = $response->json('data.createTag');
        $this->graphQL(/** @lang GRAPHQL */
            '
                mutation followTag(
                    $tag_id: ID!
                ) 
                {
                    followTag(id: $tag_id)
                }
            ',
            [
                'tag_id' => $tag['id'],
            ]
        )->assertJson([
            'data' => [
                'followTag' => true,
            ],
        ]);
    }
}
