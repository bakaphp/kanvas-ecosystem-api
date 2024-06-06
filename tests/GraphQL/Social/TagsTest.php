<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Baka\Support\Str;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
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

    public function testCreateTagWithoutSlug()
    {
        $input = [
            'name' => fake()->name(),
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

    public function testAttachTagToMessage()
    {

        $messageType = MessageType::factory()->create();
        $message = fake()->text();
        Message::makeAllSearchable();

        $response = $this->graphQL(
            '
                mutation createMessage($input: MessageInput!) {
                    createMessage(input: $input) {
                        id
                        message
                    }
                }
            ',
            [
                'input' => [
                    'message' => $message,
                    'message_verb' => $messageType->verb,
                    'system_modules_id' => 1,
                    'entity_id' => '1',
                ],
            ]
        );
        $systemModule = SystemModules::find(1);

        $message = $response->json('data.createMessage');

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
        $attach = [
            'tag_id' => $tag['id'],
            'system_module_name' => $systemModule->name,
            'entity_id' => $message['id'],
        ];
        $this->graphQL(/** @lang GRAPHQL */
            '
                mutation attachTagToEntity(
                    $input: AttachTagEntityInput!
                ) 
                {
                    attachTagToEntity(input: $input)
                }
            ',
            [
                'input' => $attach,
            ]
        )->assertJson([
            'data' => [
                'attachTagToEntity' => true,
            ],
        ]);

        $this->graphQL('
            query tag(
                $where: QueryTagsWhereWhereConditions
            ){
                tags(where: $where) {
                    data {
                        id
                        name
                        slug
                        weight
                        taggables {
                            tags_id
                            entity_id
                        }
                    }
                }
            }
        ', [
            'where' => [
                'value' => $tag['id'],
                'column' => 'ID',
                'operator' => 'EQ',
            ],
        ])->assertJson([
            'data' => [
                'tags' => [
                    'data' => [
                        [
                            'id' => $tag['id'],
                            'name' => $input['name'],
                            'slug' => $input['slug'],
                            'weight' => $input['weight'],
                            'taggables' => [
                                [
                                    'tags_id' => $tag['id'],
                                    'entity_id' => $message['id'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
