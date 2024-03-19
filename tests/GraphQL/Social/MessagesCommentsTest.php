<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Tests\TestCase;

class MessagesCommentsTest extends TestCase
{
    public function testCreateComment()
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

        $response->assertJson([
            'data' => [
                'createMessage' => [
                    'message' => $message,
                ],
            ],
        ]);

        $id = $response->json('data.createMessage.id');

        $comment = fake()->text();
        $this->graphQL(
            '
                mutation addComment($input: CommentInput!) {
                    addComment(input: $input) {
                        comments {
                            message
                        }
                    }
                }
            ',
            [
                'input' => [
                    'message_id' => $id,
                    'message' => $comment,
                ],
            ]
        )->assertJson([
            'data' => [
                'addComment' => [
                    'comments' => [
                        [
                            'message' => $comment,
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testUpdateComment()
    {
        $message = Message::orderBy('id', 'desc')->first();
        $messageType = MessageType::orderBy('id', 'desc')->first();

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
                    'message' => fake()->text(),
                    'message_verb' => $messageType->verb,
                    'system_modules_id' => 1,
                    'entity_id' => '1',
                ],
            ]
        );

        $messageId = $response->json('data.createMessage.id');


        $comment = fake()->text();
        $response = $this->graphQL(
            '
                mutation addComment($input: CommentInput!) {
                    addComment(input: $input) {
                        comments {
                            id,
                            message
                        }
                    }
                }
            ',
            [
                'input' => [
                    'message_id' => $messageId,
                    'message' => $comment,
                ],
            ]
        );
        $commentId = $response->json('data.addComment.comments.0.id');

        $comment = fake()->text();

        $this->graphQL(
            '
                mutation updateComment($input: CommentInput!, $comment_id: ID!) {
                    updateComment(input: $input, comment_id: $comment_id) {
                        comments {
                            message
                        }
                    }
                }
            ',
            [
                'input' => [
                    'message' => $comment,
                    'message_id' => $message->id,
                ],
                'comment_id' => $commentId,
            ]
        )->assertJson([
            'data' => [
                'updateComment' => [
                    'comments' => [
                        [
                            'message' => $comment,
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function testDeleteComment()
    {
        $message = Message::orderBy('id', 'desc')->first();
        $messageType = MessageType::orderBy('id', 'desc')->first();

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
                    'message' => fake()->text(),
                    'message_verb' => $messageType->verb,
                    'system_modules_id' => 1,
                    'entity_id' => '1',
                ],
            ]
        );

        $messageId = $response->json('data.createMessage.id');


        $comment = fake()->text();
        $response = $this->graphQL(
            '
                mutation addComment($input: CommentInput!) {
                    addComment(input: $input) {
                        comments {
                            id,
                            message
                        }
                    }
                }
            ',
            [
                'input' => [
                    'message_id' => $messageId,
                    'message' => $comment,
                ],
            ]
        );
        $commentId = $response->json('data.addComment.comments.0.id');

        $this->graphQL(
            '
                mutation deleteComment($id: ID!) {
                    deleteComment(id: $id) 
                }
            ',
            [
                'id' => $commentId,
            ]
        )->assertJson([
            'data' => [
                'deleteComment' => true,
            ],
        ]);
    }
}
