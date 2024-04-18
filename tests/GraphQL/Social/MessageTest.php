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
                    }
                }
            ',
            [
                'id' => $createdMessageId,
                'input' => [
                    'message' => $newMessage,
                ],
            ]
        )->assertJson([
            'data' => [
                'updateMessage' => [
                    'message' => $newMessage,
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
                    deleteMessage(id: $id) {
                        id
                    }
                }
            ',
            [
                'id' => $createdMessageId,
            ]
        )->assertJson([
            'data' => [
                'deleteMessage' => [
                    'id' => $createdMessageId,
                ],
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
                        users {
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
                    users {
                        id
                    }
                  }
                }
              }
            '
        )->assertSuccessful();
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
}
