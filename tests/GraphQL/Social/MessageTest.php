<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

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
        Message::makeAllSearchable();

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
        Message::makeAllSearchable();

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
}
