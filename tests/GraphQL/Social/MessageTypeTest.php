<?php

declare(strict_types=1);

namespace Tests\GraphQL\Social;

use Kanvas\Languages\Models\Languages;
use Tests\TestCase;

class MessageTypeTest extends TestCase
{
    public function testCreateMessageType()
    {
        $language = Languages::factory()->create();
        $name = fake()->name();
        $this->graphQL(/** @lang GRAPHQL */
            '
                mutation createMessageType(
                    $input: CreateMessageTypeInput!
                ) 
                {
                    createMessageType(input: $input) {
                        id
                        name
                    }
                }
            ',
            [
                'input' => [
                    'name' => $name,
                    'languages_id' => $language->id,
                    'verb' => 'test - ' . $name,
                    'template' => '<fake>',
                    'templates_plura' => '<fake>',
                ],
            ]
        )->assertJson([
            'data' => [
                'createMessageType' => [
                    'name' => $name,
                ],
            ],
        ]);
    }

    public function testUpdateMessageType()
    {
        $language = Languages::factory()->create();
        $name = fake()->name();
        $response = $this->graphQL(/** @lang GRAPHQL */
            '
                mutation createMessageType(
                    $input: CreateMessageTypeInput!
                ) 
                {
                    createMessageType(input: $input) {
                        id
                        name
                    }
                }
            ',
            [
                'input' => [
                    'name' => $name,
                    'languages_id' => $language->id,
                    'verb' => 'test - ' . $name,
                    'template' => '<fake>',
                    'templates_plura' => '<fake>',
                ],
            ]
        );
        $this->graphQL(/** @lang GRAPHQL */
            '
                mutation updateMessageType(
                    $id: Int!
                    $input: CreateMessageTypeInput!
                ) 
                {
                    updateMessageType(id: $id, input: $input) {
                        id
                        name
                    }
                }
            ',
            [
                'id' => $response->json('data.createMessageType.id'),
                'input' => [
                    'name' => $name . ' - updated',
                    'languages_id' => $language->id,
                    'verb' => 'test - ' . $name,
                    'template' => '<fake>',
                    'templates_plura' => '<fake>',
                ],
            ]
        )->assertJson([
            'data' => [
                'updateMessageType' => [
                    'name' => $name . ' - updated',
                ],
            ],
        ]);
    }

    public function testGetMessageType()
    {
        $language = Languages::factory()->create();
        $name = fake()->name();
        $response = $this->graphQL(/** @lang GRAPHQL */
            '
                mutation createMessageType(
                    $input: CreateMessageTypeInput!
                )
                {
                    createMessageType(input: $input) {
                        id
                        name
                    }
                }
            ',
            [
                'input' => [
                    'name' => $name,
                    'languages_id' => $language->id,
                    'verb' => 'test - ' . $name,
                    'template' => '<fake>',
                    'templates_plura' => '<fake>',
                ],
            ]
        );
        $this->graphQL(/** @lang GRAPHQL */
            '
                {
                    messageTypes(orderBy: {column: ID, order: DESC}) {
                        data {
                            id
                            name
                        }
                    }
                }
            '
        )->assertSee([
            $name,
        ]);
    }

    public function testDeleteMessageType()
    {
        $language = Languages::factory()->create();
        $name = fake()->name();
        $createResponse = $this->graphQL(/** @lang GRAPHQL */
            '
                mutation createMessageType($input: CreateMessageTypeInput!) {
                    createMessageType(input: $input) {
                        id
                        name
                    }
                }
            ',
            [
                'input' => [
                    'name' => $name,
                    'languages_id' => $language->id,
                    'verb' => 'test-delete-' . $name,
                    'template' => '<fake>',
                    'templates_plura' => '<fake>',
                ],
            ]
        )->assertSuccessful();

        $id = $createResponse->json('data.createMessageType.id');

        $this->graphQL(/** @lang GRAPHQL */
            '
                mutation deleteMessageType($id: ID!) {
                    deleteMessageType(id: $id)
                }
            ',
            ['id' => $id]
        )->assertSuccessful()
        ->assertJson([
            'data' => [
                'deleteMessageType' => true,
            ],
        ]);
    }

    public function testCannotDeleteMessageTypeInUse()
    {
        $app = app(\Kanvas\Apps\Models\Apps::class);
        $messageType = \Kanvas\Social\MessagesTypes\Models\MessageType::fromApp($app)
            ->whereHas('messages')
            ->first();

        if (! $messageType) {
            $this->markTestSkipped('No message type with messages found to test deletion prevention');
        }

        $this->graphQL(/** @lang GRAPHQL */
            '
                mutation deleteMessageType($id: ID!) {
                    deleteMessageType(id: $id)
                }
            ',
            ['id' => $messageType->id]
        )->assertJsonFragment([
            'message' => 'Cannot delete a message type that has been used by messages',
        ]);
    }
}
