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
                'viewMessage' => 1,
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
}
