<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
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
        $app = app(Apps::class);

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
        $systemModule = SystemModules::fromApp($app)
                        ->where('model_name', Message::class)
                        ->first();

        $message = $response->json('data.createMessage');

        $input = [
            'name' => fake()->name(),
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
            'system_module_uuid' => $systemModule->uuid,
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

        $this->graphQL(
            '
            query message(
                $where: QueryMessagesWhereWhereConditions
            ){
                messages(where: $where) {
                    data {
                        id
                        message
                        tags {
                            data {
                                id
                                name
                                slug
                                weight
                            }
                        }
                    }
                }
            }',
            [
                'where' => [
                    'value' => $message['id'],
                    'column' => 'ID',
                    'operator' => 'EQ',
                ],
            ]
        )->assertJson([
                'data' => [
                    'messages' => [
                        'data' => [
                            [
                                'id' => $message['id'],
                                'message' => $message['message'],
                                'tags' => [
                                    'data' => [
                                        [
                                            'id' => $tag['id'],
                                            'name' => $input['name'],
                                            'weight' => $input['weight'],
                                        ]
                                     ],
                                ]
                            ],
                        ],
                    ],
                ],
            ]);
    }

    public function testTagParentChildRelationship(): void
    {
        // Create parent tag
        $parentInput = [
            'name' => fake()->name(),
            'weight' => random_int(1, 100),
        ];

        $parentResponse = $this->graphQL(
            '
                mutation createTag($input: TagInput!) {
                    createTag(input: $input) {
                        id
                        name
                        weight
                    }
                }
            ',
            ['input' => $parentInput]
        );

        $parentId = $parentResponse->json('data.createTag.id');

        // Create child tag with parent_id
        $childInput = [
            'name' => fake()->name(),
            'weight' => random_int(1, 100),
            'parent_id' => $parentId,
        ];

        $childResponse = $this->graphQL(
            '
                mutation createTag($input: TagInput!) {
                    createTag(input: $input) {
                        id
                        name
                        weight
                        parent {
                            id
                            name
                        }
                    }
                }
            ',
            ['input' => $childInput]
        );

        $childResponse->assertJsonPath('data.createTag.parent.id', $parentId);
        $childId = $childResponse->json('data.createTag.id');

        // Query parent tag and verify children relationship
        $response = $this->graphQL(
            '
                query($where: QueryTagsWhereWhereConditions) {
                    tags(where: $where) {
                        data {
                            id
                            name
                            children(first: 10) {
                                data {
                                    id
                                    name
                                }
                            }
                        }
                    }
                }
            ',
            [
                'where' => [
                    'column' => 'ID',
                    'operator' => 'EQ',
                    'value' => $parentId,
                ],
            ]
        );

        $children = $response->json('data.tags.data.0.children.data');
        $this->assertNotEmpty($children);
        $childIds = array_column($children, 'id');
        $this->assertContains($childId, $childIds);
    }

    public function testTagMultipleChildrenRelationship(): void
    {
        // Create parent tag
        $parentInput = [
            'name' => fake()->name(),
            'weight' => random_int(1, 100),
        ];

        $parentResponse = $this->graphQL(
            '
                mutation createTag($input: TagInput!) {
                    createTag(input: $input) {
                        id
                        name
                    }
                }
            ',
            ['input' => $parentInput]
        );

        $parentId = $parentResponse->json('data.createTag.id');

        // Create multiple child tags
        $childIds = [];
        for ($i = 0; $i < 3; $i++) {
            $childInput = [
                'name' => fake()->name(),
                'weight' => random_int(1, 100),
                'parent_id' => $parentId,
            ];

            $childResponse = $this->graphQL(
                '
                    mutation createTag($input: TagInput!) {
                        createTag(input: $input) {
                            id
                            name
                            parent {
                                id
                            }
                        }
                    }
                ',
                ['input' => $childInput]
            );

            $childResponse->assertJsonPath('data.createTag.parent.id', $parentId);
            $childIds[] = $childResponse->json('data.createTag.id');
        }

        // Query parent and verify all children
        $response = $this->graphQL(
            '
                query($where: QueryTagsWhereWhereConditions) {
                    tags(where: $where) {
                        data {
                            id
                            name
                            children(first: 10) {
                                data {
                                    id
                                    name
                                }
                            }
                        }
                    }
                }
            ',
            [
                'where' => [
                    'column' => 'ID',
                    'operator' => 'EQ',
                    'value' => $parentId,
                ],
            ]
        );

        $children = $response->json('data.tags.data.0.children.data');
        $this->assertCount(3, $children);

        $returnedChildIds = array_column($children, 'id');
        foreach ($childIds as $childId) {
            $this->assertContains($childId, $returnedChildIds);
        }
    }

    public function testUpdateTagParent(): void
    {
        // Create two parent tags
        $parent1Response = $this->graphQL(
            '
                mutation createTag($input: TagInput!) {
                    createTag(input: $input) {
                        id
                        name
                    }
                }
            ',
            [
                'input' => [
                    'name' => fake()->name(),
                    'weight' => random_int(1, 100),
                ],
            ]
        );
        $parent1Id = $parent1Response->json('data.createTag.id');

        $parent2Response = $this->graphQL(
            '
                mutation createTag($input: TagInput!) {
                    createTag(input: $input) {
                        id
                        name
                    }
                }
            ',
            [
                'input' => [
                    'name' => fake()->name(),
                    'weight' => random_int(1, 100),
                ],
            ]
        );
        $parent2Id = $parent2Response->json('data.createTag.id');

        // Create child tag under parent1
        $childInput = [
            'name' => fake()->name(),
            'weight' => random_int(1, 100),
            'parent_id' => $parent1Id,
        ];

        $childResponse = $this->graphQL(
            '
                mutation createTag($input: TagInput!) {
                    createTag(input: $input) {
                        id
                        name
                        parent {
                            id
                        }
                    }
                }
            ',
            ['input' => $childInput]
        );

        $childId = $childResponse->json('data.createTag.id');
        $childResponse->assertJsonPath('data.createTag.parent.id', $parent1Id);

        // Update child to have parent2 as parent
        $updateInput = [
            'name' => fake()->name(),
            'weight' => random_int(1, 100),
            'parent_id' => $parent2Id,
        ];

        $updateResponse = $this->graphQL(
            '
                mutation updateTag($id: ID!, $input: TagInput!) {
                    updateTag(id: $id, input: $input) {
                        id
                        name
                        parent {
                            id
                        }
                    }
                }
            ',
            [
                'id' => $childId,
                'input' => $updateInput,
            ]
        );

        $updateResponse->assertJsonPath('data.updateTag.parent.id', $parent2Id);
    }
}
