<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Kanvas\Social\MessagesTypes\Models\MessageType;
use Tests\TestCase;

class MessageInteractionTest extends TestCase
{
    public function testLikeMessage()
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
        )->assertJson([
            'data' => [
                'createMessage' => [
                    'message' => $message,
                ],
            ],
        ]);

        $id = $response->json('data.createMessage.id');

        $response = $this->graphQL(
            '
                mutation likeMessage($id: ID!) {
                    likeMessage(id: $id)
                }
            ',
            [
                'id' => $id,
            ]
        )->assertJson([
            'data' => [
                'likeMessage' => true,
            ],
        ]);
    }

    public function testViewMessage()
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
        )->assertJson([
            'data' => [
                'createMessage' => [
                    'message' => $message,
                ],
            ],
        ]);

        $id = $response->json('data.createMessage.id');

        $response = $this->graphQL(
            '
                mutation viewMessage($id: ID!) {
                    viewMessage(id: $id)
                }
            ',
            [
                'id' => $id,
            ]
        )->assertJson([
            'data' => [
                'viewMessage' => 2,
            ],
        ]);
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

        $id = $response->json('data.createMessage.id');

        $response = $this->graphQL(
            '
                mutation shareMessage($id: ID!) {
                    shareMessage(id: $id)
                }
            ',
            [
                'id' => $id,
            ]
        )->assertJson([
            'data' => [
                'shareMessage' => true,
            ],
        ]);
    }

    public function testDisLikeMessage()
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
        )->assertJson([
            'data' => [
                'createMessage' => [
                    'message' => $message,
                ],
            ],
        ]);

        $id = $response->json('data.createMessage.id');

        $response = $this->graphQL(
            '
                mutation disLikeMessage($id: ID!) {
                    disLikeMessage(id: $id)
                }
            ',
            [
                'id' => $id,
            ]
        )->assertJson([
            'data' => [
                'disLikeMessage' => true,
            ],
        ]);
    }

    /**
    * testMessagesLikedByUser
    *
    * @return void
    */
    public function testMessagesLikedByUser()
    {
        $messageType = MessageType::factory()->create();
        $message = fake()->text();
        $user = auth()->user();

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
        )->assertJson([
            'data' => [
                'createMessage' => [
                    'message' => $message,
                ],
            ],
        ]);

        $id = $response->json('data.createMessage.id');

        $response = $this->graphQL(
            '
                mutation likeMessage($id: ID!) {
                    likeMessage(id: $id)
                }
            ',
            [
                'id' => $id,
            ]
        )->assertJson([
            'data' => [
                'likeMessage' => true,
            ],
        ]);

        // Run the GraphQL query
        $this->graphQL(
            '
                query messagesLikedByUser($id: ID!) {
                    messagesLikedByUser(id: $id) {
                        data {
                            id
                            slug
                            message
                        }
                    }
                }
            ',
            [
                'id' => $user->id,
            ]
        )->assertJson([
            'data' => [
                'messagesLikedByUser' => [
                    'data' => [
                        [
                            'id' => $id,
                        ],
                    ],
                ],
            ],
        ]);
    }
}
