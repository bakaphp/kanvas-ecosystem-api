<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

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
        $this->graphQL(
            '
                mutation createMessage($input: MessageInput!) {
                    createMessage(input: $input) {
                        id
                        message
                        message_types_id
                    }
                }
            ',
            [
                'input' => [
                    'message' => $message,
                    'message_types_id' => $messageType->id,
                    'system_modules_id' => 1,
                    'entity_id' => '1',
                ],
            ]
        )->assertJson([
            'data' => [
                'createMessage' => [
                    'message' => $message,
                    'message_types_id' => $messageType->id,
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
                        message_types_id
                    }
                }
            ',
            [
                'input' => [
                    'message' => $message,
                    'message_types_id' => $messageType->id,
                    'system_modules_id' => 1,
                    'entity_id' => '1',
                ],
            ]
        );

        $this->graphQL(
            '
                query messageSearch($text: String!) {
                    messageSearch(search: $text) {
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
                'messageSearch' => [
                    'data' => [
                        [
                            'message' => $message,
                            'message_types_id' => $messageType->id,
                        ],
                    ],
                ],
            ],
        ]);
    }
}
