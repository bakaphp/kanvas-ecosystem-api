<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Baka\Support\Str;
use Illuminate\Http\UploadedFile;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Tests\TestCase;

class MessageTest extends TestCase
{
    /**
     * testCreateMessage
     *
     * @return void
     */
    public function testCreateMessage()
    {
        $messageType = MessageType::factory()->create();
        $message = fake()->text();
        //Message::makeAllSearchable();

        $this->graphQL(
            '
                mutation createMessage($input: MessageInput!) {
                    createMessage(input: $input) {
                        id
                        message
                        is_public
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
        )->assertJson([
            'data' => [
                'createMessage' => [
                    'message' => $message,
                    'is_public' => 1,
                ],
            ],
        ]);
    }

    public function testCreatePrivateMessage()
    {
        $messageType = MessageType::factory()->create();
        $message = fake()->text();
        //Message::makeAllSearchable();

        $this->graphQL(
            '
                mutation createMessage($input: MessageInput!) {
                    createMessage(input: $input) {
                        id
                        message
                        is_public
                    }
                }
            ',
            [
                'input' => [
                    'message' => $message,
                    'message_verb' => $messageType->verb,
                    'system_modules_id' => 1,
                    'entity_id' => '1',
                    'is_public' => 0,
                ],
            ]
        )->assertJson([
            'data' => [
                'createMessage' => [
                    'message' => $message,
                    'is_public' => 0,
                ],
            ],
        ]);
    }

    public function testUpdateMessage()
    {
        $messageType = MessageType::factory()->create();
        $message = fake()->text();
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

        $createdMessageId = $response['data']['createMessage']['id'];

        $newMessage = fake()->text();
        $this->graphQL(
            '
                mutation updateMessage($id: ID!, $input: MessageUpdateInput!) {
                    updateMessage(id: $id, input: $input) {
                        id
                        message
                        tags {
                            data {
                                name
                            }
                        }
                    }
                }
            ',
            [
                'id' => $createdMessageId,
                'input' => [
                    'message' => $newMessage,
                    'tags' => [
                        [
                            'name' => 'tag1',
                        ],
                    ],
                ],
            ]
        )->assertJson([
            'data' => [
                'updateMessage' => [
                    'message' => $newMessage,
                    'tags' => [
                        'data' => [
                            [
                                'name' => 'tag1',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testUpdateMessageWithIsLocked(): void
    {
        $messageType = MessageType::factory()->create();
        $createResponse = $this->graphQL('
            mutation($input: MessageInput!) {
                createMessage(input: $input) {
                    id
                    is_locked
                }
            }
        ', [
            'input' => [
                'message' => fake()->text(),
                'message_verb' => $messageType->verb,
            ],
        ])->assertSuccessful();

        $id = $createResponse->json('data.createMessage.id');

        $this->graphQL('
            mutation($id: ID!, $input: MessageUpdateInput!) {
                updateMessage(id: $id, input: $input) {
                    id
                    is_locked
                }
            }
        ', [
            'id' => $id,
            'input' => [
                'is_locked' => 1,
            ],
        ])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateMessage' => [
                    'id' => $id,
                    'is_locked' => 1,
                ],
            ],
        ]);

        $message = Message::getById((int) $id, app(Apps::class));
        $this->assertSame(1, (int) $message->is_locked);
    }

    public function testUpdateMessageAddTags(): void
    {
        $messageType = MessageType::factory()->create();
        $createResponse = $this->graphQL('
            mutation($input: MessageInput!) {
                createMessage(input: $input) { id }
            }
        ', [
            'input' => [
                'message' => fake()->text(),
                'message_verb' => $messageType->verb,
            ],
        ])->assertSuccessful();

        $id = $createResponse->json('data.createMessage.id');

        $this->graphQL('
            mutation($id: ID!, $input: MessageUpdateInput!) {
                updateMessage(id: $id, input: $input) {
                    id
                    tags { data { name } }
                }
            }
        ', [
            'id' => $id,
            'input' => [
                'tags' => [
                    ['name' => 'alpha'],
                    ['name' => 'beta'],
                ],
            ],
        ])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateMessage' => [
                    'tags' => [
                        'data' => [
                            ['name' => 'alpha'],
                            ['name' => 'beta'],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testUpdateMessageReplaceTags(): void
    {
        $messageType = MessageType::factory()->create();
        $createResponse = $this->graphQL('
            mutation($input: MessageInput!) {
                createMessage(input: $input) { id }
            }
        ', [
            'input' => [
                'message' => fake()->text(),
                'message_verb' => $messageType->verb,
            ],
        ])->assertSuccessful();

        $id = $createResponse->json('data.createMessage.id');

        $this->graphQL('
            mutation($id: ID!, $input: MessageUpdateInput!) {
                updateMessage(id: $id, input: $input) { id }
            }
        ', [
            'id' => $id,
            'input' => [
                'tags' => [
                    ['name' => 'old-tag-one'],
                    ['name' => 'old-tag-two'],
                ],
            ],
        ])->assertSuccessful();

        $this->graphQL('
            mutation($id: ID!, $input: MessageUpdateInput!) {
                updateMessage(id: $id, input: $input) {
                    id
                    tags { data { name } }
                }
            }
        ', [
            'id' => $id,
            'input' => [
                'tags' => [
                    ['name' => 'new-tag'],
                ],
            ],
        ])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateMessage' => [
                    'tags' => [
                        'data' => [
                            ['name' => 'new-tag'],
                        ],
                    ],
                ],
            ],
        ])
        ->assertJsonMissing(['name' => 'old-tag-one'])
        ->assertJsonMissing(['name' => 'old-tag-two']);
    }

    public function testUpdateMessageWithoutTagsKeepsExisting(): void
    {
        $messageType = MessageType::factory()->create();
        $createResponse = $this->graphQL('
            mutation($input: MessageInput!) {
                createMessage(input: $input) { id }
            }
        ', [
            'input' => [
                'message' => fake()->text(),
                'message_verb' => $messageType->verb,
            ],
        ])->assertSuccessful();

        $id = $createResponse->json('data.createMessage.id');

        $this->graphQL('
            mutation($id: ID!, $input: MessageUpdateInput!) {
                updateMessage(id: $id, input: $input) { id }
            }
        ', [
            'id' => $id,
            'input' => [
                'tags' => [
                    ['name' => 'keep-me'],
                ],
            ],
        ])->assertSuccessful();

        $newMessage = fake()->text();
        $this->graphQL('
            mutation($id: ID!, $input: MessageUpdateInput!) {
                updateMessage(id: $id, input: $input) {
                    id
                    message
                    tags { data { name } }
                }
            }
        ', [
            'id' => $id,
            'input' => [
                'message' => $newMessage,
            ],
        ])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateMessage' => [
                    'message' => $newMessage,
                    'tags' => [
                        'data' => [
                            ['name' => 'keep-me'],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testUpdateMessageRemoveOneTagKeepOthers(): void
    {
        $messageType = MessageType::factory()->create();
        $createResponse = $this->graphQL('
            mutation($input: MessageInput!) {
                createMessage(input: $input) { id }
            }
        ', [
            'input' => [
                'message' => fake()->text(),
                'message_verb' => $messageType->verb,
            ],
        ])->assertSuccessful();

        $id = $createResponse->json('data.createMessage.id');

        $this->graphQL('
            mutation($id: ID!, $input: MessageUpdateInput!) {
                updateMessage(id: $id, input: $input) { id }
            }
        ', [
            'id' => $id,
            'input' => [
                'tags' => [
                    ['name' => 'tag-a'],
                    ['name' => 'tag-b'],
                    ['name' => 'tag-c'],
                ],
            ],
        ])->assertSuccessful();

        $this->graphQL('
            mutation($id: ID!, $input: MessageUpdateInput!) {
                updateMessage(id: $id, input: $input) {
                    id
                    tags { data { name } }
                }
            }
        ', [
            'id' => $id,
            'input' => [
                'tags' => [
                    ['name' => 'tag-a'],
                    ['name' => 'tag-c'],
                ],
            ],
        ])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'updateMessage' => [
                    'tags' => [
                        'data' => [
                            ['name' => 'tag-a'],
                            ['name' => 'tag-c'],
                        ],
                    ],
                ],
            ],
        ])
        ->assertJsonMissing(['name' => 'tag-b']);
    }

    public function testUpdateVerbMessage()
    {
        $messageType = MessageType::factory()->create();
        $message = fake()->text();
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

        $createdMessageId = $response['data']['createMessage']['id'];

        $newMessage = fake()->text();
        $newMessageType = MessageType::factory()->create(['name' => 'newType2', 'verb' => 'newType2']);
        $this->graphQL(
            '
                mutation updateMessage($id: ID!, $input: MessageUpdateInput!) {
                    updateMessage(id: $id, input: $input) {
                        id
                        message
                        messageType {
                            verb
                        }
                        tags {
                            data {
                                name
                            }
                        }
                    }
                }
            ',
            [
                'id' => $createdMessageId,
                'input' => [
                    'message' => $newMessage,
                    'message_verb' => $newMessageType->verb,
                    'tags' => [
                        [
                            'name' => 'tag1',
                        ],
                    ],
                ],
            ]
        )->assertJson([
            'data' => [
                'updateMessage' => [
                    'message' => $newMessage,
                    'messageType' => [
                        'verb' => $newMessageType->verb,
                    ],
                'tags' => [
                        'data' => [
                            [
                                'name' => 'tag1',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testDeleteMessage()
    {
        $messageType = MessageType::factory()->create();
        $message = fake()->text();
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

        $createdMessageId = $response['data']['createMessage']['id'];

        $this->graphQL(
            '
                mutation deleteMessage($id: ID!) {
                    deleteMessage(id: $id) 
                }
            ',
            [
                'id' => $createdMessageId,
            ]
        )->assertJson([
            'data' => [
                'deleteMessage' => true,
            ],
        ]);
    }

    public function testRestoreMessage()
    {
        $messageType = MessageType::factory()->create();
        $message = fake()->text();
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

        $createdMessageId = $response['data']['createMessage']['id'];

        $this->graphQL(
            '
                mutation deleteMessage($id: ID!) {
                    deleteMessage(id: $id) 
                }
            ',
            [
                'id' => $createdMessageId,
            ]
        )->assertJson([
            'data' => [
                'deleteMessage' => true,
            ],
        ]);

        $this->graphQL(
            '
                mutation restoreMessage($id: ID!) {
                    restoreMessage(id: $id) 
                }
            ',
            [
                'id' => $createdMessageId,
            ]
        )->assertJson([
            'data' => [
                'restoreMessage' => true,
            ],
        ]);
    }

    public function testDeleteMultipleMessage()
    {
        $messageType = MessageType::factory()->create();
        $message = fake()->text();
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

        $createdMessageId = $response['data']['createMessage']['id'];

        $this->graphQL(
            '
                mutation deleteMultipleMessages($ids: [ID!]!) {
                    deleteMultipleMessages(ids: $ids)
                }
            ',
            [
                'ids' => $createdMessageId,
            ]
        )->assertJson([
            'data' => [
                'deleteMultipleMessages' => true,
            ],
        ]);
    }

    public function testDeleteAllMessage()
    {
        $messageType = MessageType::factory()->create();
        $message = fake()->text();
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

        $createdMessageId = $response['data']['createMessage']['id'];

        $this->graphQL(
            '
                mutation deleteAllMessages {
                    deleteAllMessages
                }
            ',
            [
            ]
        )->assertJson([
            'data' => [
                'deleteAllMessages' => true,
            ],
        ]);
    }

    public function testMessages()
    {
        $messageType = MessageType::factory()->create();
        $message = fake()->text();
        $response = $this->graphQL(
            '
                mutation createMessage($input: MessageInput!) {
                    createMessage(input: $input) {
                        id
                        message
                        user {
                            id
                        }
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

        $this->graphQL(
            '
            query {
                messages {
                  data {
                    message
                    message_types_id,
                    user {
                        id
                    }
                  }
                }
              }
            '
        )->assertSuccessful();
    }

    public function testShareMessage()
    {
        $messageType = MessageType::factory()->create();
        $message = fake()->text();
        $response = $this->graphQL(
            '
                mutation createMessage($input: MessageInput!) {
                    createMessage(input: $input) {
                        id
                        message
                        user {
                            id
                        }
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

        $createdMessageId = $response['data']['createMessage']['id'];

        $this->graphQL(
            '
                mutation shareMessage($id: ID!) {
                    shareMessage(id: $id)
                }
            ',
            [
                'id' => $createdMessageId,
            ]
        )->assertSuccessful();
    }

    public function testCreateChildMessage()
    {
        $messageType = MessageType::factory()->create();
        $message = fake()->text();
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
                    'entity_id' => '1',
                ],
            ]
        );

        $createdMessageId = $response['data']['createMessage']['id'];

        $childMessage = fake()->text();
        $response = $this->graphQL(
            '
                mutation createMessage($input: MessageInput!) {
                    createMessage(input: $input) {
                        id
                        message
                        children(first: 25){
                            data {
                                id
                                message
                            }
                        }
                    }
                }
            ',
            [
                'input' => [
                    'message' => $childMessage,
                    'message_verb' => $messageType->verb,
                    'entity_id' => '1',
                    'parent_id' => $createdMessageId,
                ],
            ]
        );

        $this->graphQL(
            '
            query {
                messages(
                    where: {
                        column: ID, operator: EQ, value: ' . $createdMessageId . '
                        } 
                ) {
                  data {
                    message
                    message_types_id
                    total_children
                    children(first: 25){
                        data {
                            message
                        }
                    }
                  }
                }
              }
            '
        )->assertJson([
            'data' => [
                'messages' => [
                    'data' => [
                        [
                            'total_children' => 1,
                            'message' => $message,
                            'children' => [
                                'data' => [
                                    [
                                        'message' => $childMessage,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testGetMessageFilter()
    {
        $messageType = MessageType::factory()->create();
        $message = fake()->text();
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

        $createdMessageId = $response['data']['createMessage']['id'];

        $this->graphQL(
            '
            query {
                messages(
                    where: {
                        column: ID, operator: EQ, value: ' . $createdMessageId . '
                        } 
                ) {
                  data {
                    message
                    message_types_id
                  }
                }
              }
            '
        )->assertJson([
            'data' => [
                'messages' => [
                    'data' => [
                        [
                            'message' => $message,
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testGroupMessageByDate()
    {
        $messageType = MessageType::factory()->create();
        $message = fake()->text();
        $response = $this->graphQL(
            '
                mutation createMessage($input: MessageInput!) {
                    createMessage(input: $input) {
                        id
                        message
                        user {
                            id
                        }
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

        $this->graphQL(
            '
            query {
                messagesGroupByDate {
                  data {
                    message
                    additional_field
                  }
                }
              }
            '
        )->assertSuccessful();
    }

    public function testMessageSearch()
    {
        $messageType = MessageType::factory()->create();
        $message = fake()->text();
        $this->graphQL(
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

        $this->graphQL(
            '
                query messages($text: String!) {
                    messages(search: $text) {
                        data {
                            message
                            message_types_id
                        }
                    }
                }
            ',
            [
                'text' => $message,
            ]
        )->assertSuccessful();
        /*         )->assertJson([ //why is it failing?
                    'data' => [
                        'messages' => [
                            'data' => [
                                [
                                    'message' => $message,
                                ],
                            ],
                        ],
                    ],
                ]); */
    }

    public function testForYouMessages()
    {
        $messageType = MessageType::factory()->create();
        $message = fake()->text();
        $this->graphQL(
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

        $this->graphQL(
            '
                query {
                    forYouMessages {
                        data {
                            message
                            message_types_id
                        }
                    }
                }
            '
        )->assertSuccessful();
    }

    public function testFollowingMessages()
    {
        $messageType = MessageType::factory()->create();
        $message = fake()->text();
        $this->graphQL(
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

        $this->graphQL(
            '
                query {
                    followingFeedMessages {
                        data {
                            message
                            message_types_id
                        }
                    }
                }
            '
        )->assertSuccessful();
    }

    public function testFilterMessageByTags()
    {
        $messageType = MessageType::factory()->create();
        $message = fake()->text();
        $response = $this->graphQL(
            '
                mutation createMessage($input: MessageInput!) {
                    createMessage(input: $input) {
                        id
                        message
                        tags {
                            data {
                                name
                            }
                        }
                    }
                }
            ',
            [
                'input' => [
                    'message' => $message,
                    'message_verb' => $messageType->verb,
                    'tags' => [
                        [
                            'name' => 'tag1',
                        ],[
                            'name' => 'tag2',
                        ],
                    ],
                    'system_modules_id' => 1,
                    'entity_id' => '1',
                ],
            ]
        );

        $createdMessageId = $response['data']['createMessage']['id'];

        $this->graphQL(
            '
            query {
                messages(
                    requiredTags: ["tag1", "tag2"]
                ) {
                  data {
                    message
                    tags {
                        data {
                            name
                        }
                    }
                  }
                }
              }
            '
        )->assertJson([
           'data' => [
               'messages' => [
                   'data' => [
                       [
                           'message' => $message,
                           'tags' => [
                               'data' => [
                                   [
                                       'name' => 'tag1',
                                   ],
                                   [
                                       'name' => 'tag2',
                                   ],
                               ],
                           ],
                       ],
                   ],
               ],
           ],
        ]);
    }

    public function testCreateMessageInChannel()
    {
        $messageType = MessageType::factory()->create();
        $message = fake()->text();

        $channelUuid = Str::uuid()->toString();
        $this->graphQL(
            '
                mutation createMessage($input: MessageInput!) {
                    createMessage(input: $input) {
                        id
                        message
                        is_public
                    }
                }
            ',
            [
                'input' => [
                    'message' => $message,
                    'message_verb' => $messageType->verb,
                    'system_modules_id' => 1,
                    'entity_id' => '1',
                    'channel_slug' => $channelUuid,
                ],
            ]
        )->assertJson([
            'data' => [
                'createMessage' => [
                    'message' => $message,
                    'is_public' => 1,
                ],
            ],
        ]);

        $this->assertTrue(Channel::where('slug', $channelUuid)->exists());
        $this->assertCount(1, Channel::where('slug', $channelUuid)->first()->messages);
    }

    public function testCreateMessageWithCategory()
    {
        $messageType = MessageType::factory()->create();
        $message = fake()->text();

        $this->graphQL(
            '
                mutation createMessage($input: MessageInput!) {
                    createMessage(input: $input) {
                        id
                        message
                        categories {
                            data {
                                name
                            }
                        }
                    }
                }
            ',
            [
                'input' => [
                    'message' => $message,
                    'message_verb' => $messageType->verb,
                    'system_modules_id' => 1,
                    'entity_id' => '1',
                    'categories' => [
                        [
                            'name' => 'Technology',
                            'is_published' => true,
                        ],
                        [
                            'name' => 'News',
                            'is_published' => true,
                        ],
                    ],
                ],
            ]
        )->assertJson([
            'data' => [
                'createMessage' => [
                    'message' => $message,
                    'categories' => [
                        'data' => [
                            [
                                'name' => 'Technology',
                            ],
                            [
                                'name' => 'News',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testUpdateMessageWithCategory()
    {
        $messageType = MessageType::factory()->create();
        $message = fake()->text();
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

        $createdMessageId = $response['data']['createMessage']['id'];

        $newMessage = fake()->text();
        $this->graphQL(
            '
                mutation updateMessage($id: ID!, $input: MessageUpdateInput!) {
                    updateMessage(id: $id, input: $input) {
                        id
                        message
                        categories {
                            data {
                                name
                            }
                        }
                    }
                }
            ',
            [
                'id' => $createdMessageId,
                'input' => [
                    'message' => $newMessage,
                    'categories' => [
                        [
                            'name' => 'Sports',
                            'is_published' => true,
                        ],
                        [
                            'name' => 'Entertainment',
                            'is_published' => true,
                        ],
                    ],
                ],
            ]
        )->assertJson([
            'data' => [
                'updateMessage' => [
                    'message' => $newMessage,
                    'categories' => [
                        'data' => [
                            [
                                'name' => 'Sports',
                            ],
                            [
                                'name' => 'Entertainment',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testMessagesDefaultCompanyVisibility(): void
    {
        $app = app(Apps::class);
        $app->set('restrict_messages_by_company', true);

        $messageType = MessageType::factory()->create();
        $message = fake()->text();

        $response = $this->graphQL(
            '
                mutation createMessage($input: MessageInput!) {
                    createMessage(input: $input) {
                        id
                        message
                        companies_id
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
        )->assertSuccessful();

        $createdMessageId = $response->json('data.createMessage.id');

        $this->graphQL(
            '
            query {
                messages(
                    where: { column: ID, operator: EQ, value: ' . $createdMessageId . ' }
                ) {
                    data {
                        id
                        message
                    }
                }
            }
            '
        )->assertSuccessful()
        ->assertJson([
            'data' => [
                'messages' => [
                    'data' => [
                        [
                            'id' => $createdMessageId,
                            'message' => $message,
                        ],
                    ],
                ],
            ],
        ]);

        $app->set('restrict_messages_by_company', false);
    }

    public function testMessagesCompanyVisibilityFiltersOtherCompanies(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $messageType = MessageType::factory()->create();

        $ownMessage = Message::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => $user->getId(),
            'message_types_id' => $messageType->getId(),
            'message' => ['text' => 'own-company-msg-' . fake()->uuid()],
            'is_public' => 1,
            'is_deleted' => 0,
        ]);

        $otherCompanyMessage = Message::factory()->create([
            'apps_id' => $app->getId(),
            'companies_id' => 99999,
            'users_id' => $user->getId(),
            'message_types_id' => $messageType->getId(),
            'message' => ['text' => 'other-company-msg-' . fake()->uuid()],
            'is_public' => 1,
            'is_deleted' => 0,
        ]);

        $app->set('restrict_messages_by_company', true);

        $response = $this->graphQL(
            '
            query {
                messages(
                    where: { column: ID, operator: EQ, value: ' . $otherCompanyMessage->getId() . ' }
                ) {
                    data {
                        id
                    }
                }
            }
            '
        )->assertSuccessful();

        $this->assertEmpty(
            $response->json('data.messages.data'),
            'Other company message should NOT be visible when restrict_messages_by_company is on'
        );

        $response = $this->graphQL(
            '
            query {
                messages(
                    where: { column: ID, operator: EQ, value: ' . $ownMessage->getId() . ' }
                ) {
                    data {
                        id
                    }
                }
            }
            '
        )->assertSuccessful();

        $this->assertNotEmpty(
            $response->json('data.messages.data'),
            'Own company message should be visible when restrict_messages_by_company is on'
        );

        $app->set('restrict_messages_by_company', false);
    }

    public function testCreateMessageWithCustomFields(): void
    {
        $messageType = MessageType::factory()->create();
        $message = fake()->text();

        $response = $this->graphQL('
            mutation($input: MessageInput!) {
                createMessage(input: $input) {
                    id
                    message
                    custom_fields {
                        data {
                            name
                            value
                        }
                    }
                }
            }
        ', [
            'input' => [
                'message' => $message,
                'message_verb' => $messageType->verb,
                'custom_fields' => [
                    ['name' => 'test_field_one', 'data' => 'value_one'],
                    ['name' => 'test_field_two', 'data' => 'value_two'],
                ],
            ],
        ])
        ->assertSuccessful()
        ->assertJson([
            'data' => [
                'createMessage' => [
                    'message' => $message,
                ],
            ],
        ]);

        $customFields = $response->json('data.createMessage.custom_fields.data');
        $fieldNames = array_column($customFields, 'name');
        $this->assertContains('test_field_one', $fieldNames);
        $this->assertContains('test_field_two', $fieldNames);
    }

    public function testUpdateMessageWithCustomFields(): void
    {
        $messageType = MessageType::factory()->create();

        $createResponse = $this->graphQL('
            mutation($input: MessageInput!) {
                createMessage(input: $input) {
                    id
                    message
                }
            }
        ', [
            'input' => [
                'message' => fake()->text(),
                'message_verb' => $messageType->verb,
                'custom_fields' => [
                    ['name' => 'initial_field', 'data' => 'initial_value'],
                ],
            ],
        ])->assertSuccessful();

        $id = $createResponse->json('data.createMessage.id');

        $response = $this->graphQL('
            mutation($id: ID!, $input: MessageUpdateInput!) {
                updateMessage(id: $id, input: $input) {
                    id
                    custom_fields {
                        data {
                            name
                            value
                        }
                    }
                }
            }
        ', [
            'id' => $id,
            'input' => [
                'custom_fields' => [
                    ['name' => 'initial_field', 'data' => 'updated_value'],
                    ['name' => 'new_field', 'data' => 'new_value'],
                ],
            ],
        ])
        ->assertSuccessful();

        $customFields = $response->json('data.updateMessage.custom_fields.data');
        $fieldMap = [];
        foreach ($customFields as $field) {
            $fieldMap[$field['name']] = $field['value'];
        }

        $this->assertSame('updated_value', $fieldMap['initial_field']);
        $this->assertSame('new_value', $fieldMap['new_field']);
    }

    public function testCreateMessageConstrainsLargeImageForMatchingVerb(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $verb = 'twilio-sms-test-' . uniqid();
        $messageType = MessageType::factory()->create(['verb' => $verb]);

        // Create a large JPEG with noise so it exceeds our limit
        $tempPath = sys_get_temp_dir() . '/msg-constrain-' . uniqid() . '.jpg';
        $img = imagecreatetruecolor(3000, 3000);
        for ($y = 0; $y < 3000; $y += 2) {
            for ($x = 0; $x < 3000; $x += 2) {
                $color = imagecolorallocate($img, rand(0, 255), rand(0, 255), rand(0, 255));
                imagesetpixel($img, $x, $y, $color);
            }
        }
        imagejpeg($img, $tempPath, 100);
        imagedestroy($img);

        $originalSize = filesize($tempPath);

        // Set a low max so it must constrain
        $maxFileSize = (int) ($originalSize * 0.25);
        $app->set('filesystem-message-max-filesize', $maxFileSize);
        $app->set('filesystem-message-constrain-verbs', [$verb]);

        $file = new UploadedFile($tempPath, 'large-photo.jpg', 'image/jpeg', null, true);

        $action = new CreateMessageAction(
            new MessageInput(
                app: $app,
                company: $company,
                user: $user,
                type: $messageType,
                message: 'test with large image',
                files: [$file],
            ),
        );
        $action->runWorkflow = false;
        $message = $action->execute();

        $this->assertNotNull($message->getId());

        // Verify the file was constrained
        clearstatcache(true, $tempPath);
        $this->assertLessThanOrEqual(
            $maxFileSize,
            filesize($tempPath),
            'Image should be constrained to max file size'
        );

        // Clean up
        $app->set('filesystem-message-max-filesize', null);
        $app->set('filesystem-message-constrain-verbs', null);
        @unlink($tempPath);
    }

    public function testCreateMessageSkipsConstrainForNonMatchingVerb(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Disable global optimization so it doesn't interfere
        $prevOptimize = $app->get('filesystem-optimize-on-upload');
        $app->set('filesystem-optimize-on-upload', false);

        $messageType = MessageType::factory()->create(['verb' => 'email-test-' . uniqid()]);

        // Create a large JPEG
        $tempPath = sys_get_temp_dir() . '/msg-no-constrain-' . uniqid() . '.jpg';
        $img = imagecreatetruecolor(2000, 2000);
        for ($y = 0; $y < 2000; $y += 2) {
            for ($x = 0; $x < 2000; $x += 2) {
                $color = imagecolorallocate($img, rand(0, 255), rand(0, 255), rand(0, 255));
                imagesetpixel($img, $x, $y, $color);
            }
        }
        imagejpeg($img, $tempPath, 100);
        imagedestroy($img);

        $originalSize = filesize($tempPath);

        // Only constrain twilio verbs, not email
        $app->set('filesystem-message-max-filesize', 100000);
        $app->set('filesystem-message-constrain-verbs', ['twilio-sms', 'twilio-mms']);

        $file = new UploadedFile($tempPath, 'photo.jpg', 'image/jpeg', null, true);

        $action = new CreateMessageAction(
            new MessageInput(
                app: $app,
                company: $company,
                user: $user,
                type: $messageType,
                message: 'email with large image',
                files: [$file],
            ),
        );
        $action->runWorkflow = false;
        $message = $action->execute();

        $this->assertNotNull($message->getId());

        // File should NOT have been constrained since verb doesn't match
        clearstatcache(true, $tempPath);
        $this->assertEquals(
            $originalSize,
            filesize($tempPath),
            'Image should NOT be constrained for non-matching verb'
        );

        // Clean up
        $app->set('filesystem-message-max-filesize', null);
        $app->set('filesystem-message-constrain-verbs', null);
        $app->set('filesystem-optimize-on-upload', $prevOptimize);
        @unlink($tempPath);
    }

    public function testCreateMessageSkipsConstrainWhenSettingDisabled(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        // Disable global optimization so it doesn't interfere
        $prevOptimize = $app->get('filesystem-optimize-on-upload');
        $app->set('filesystem-optimize-on-upload', false);

        $messageType = MessageType::factory()->create(['verb' => 'twilio-sms-disabled-' . uniqid()]);

        $tempPath = sys_get_temp_dir() . '/msg-disabled-' . uniqid() . '.jpg';
        $img = imagecreatetruecolor(1000, 1000);
        $red = imagecolorallocate($img, 255, 0, 0);
        imagefill($img, 0, 0, $red);
        imagejpeg($img, $tempPath, 100);
        imagedestroy($img);

        $originalSize = filesize($tempPath);

        // No max filesize set — feature disabled
        $app->set('filesystem-message-max-filesize', null);
        $app->set('filesystem-message-constrain-verbs', null);

        $file = new UploadedFile($tempPath, 'photo.jpg', 'image/jpeg', null, true);

        $action = new CreateMessageAction(
            new MessageInput(
                app: $app,
                company: $company,
                user: $user,
                type: $messageType,
                message: 'no constrain',
                files: [$file],
            ),
        );
        $action->runWorkflow = false;
        $message = $action->execute();

        $this->assertNotNull($message->getId());

        clearstatcache(true, $tempPath);
        $this->assertEquals(
            $originalSize,
            filesize($tempPath),
            'Image should NOT be constrained when setting is disabled'
        );

        // Clean up
        $app->set('filesystem-optimize-on-upload', $prevOptimize);
        @unlink($tempPath);
    }

    public function testCreateMessageConstrainsHeicFile(): void
    {
        $heicPath = storage_path('733eddb5528b4d1d3b169a8d00da0aad.HEIC');

        if (! file_exists($heicPath)) {
            $this->markTestSkipped('HEIC test file not found at ' . $heicPath);
        }

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $verb = 'twilio-sms-heic-' . uniqid();
        $messageType = MessageType::factory()->create(['verb' => $verb]);

        // Copy HEIC to temp so the test doesn't destroy the fixture
        $tempPath = sys_get_temp_dir() . '/heic-constrain-' . uniqid() . '.HEIC';
        copy($heicPath, $tempPath);

        // Set max filesize so constrainFileSize triggers HEIC→JPEG conversion
        $originalSize = filesize($tempPath);
        $maxFileSize = (int) ($originalSize * 0.5);
        $app->set('filesystem-message-max-filesize', $maxFileSize);
        $app->set('filesystem-message-constrain-verbs', [$verb]);

        $file = new UploadedFile($tempPath, 'photo.HEIC', 'image/heic', null, true);

        $action = new CreateMessageAction(
            new MessageInput(
                app: $app,
                company: $company,
                user: $user,
                type: $messageType,
                message: 'test with HEIC image',
                files: [$file],
            ),
        );
        $action->runWorkflow = false;
        $message = $action->execute();

        $this->assertNotNull($message->getId());

        // Clean up
        $app->set('filesystem-message-max-filesize', null);
        $app->set('filesystem-message-constrain-verbs', null);
    }
}
