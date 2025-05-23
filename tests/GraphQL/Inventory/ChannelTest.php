<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Tests\TestCase;

class ChannelTest extends TestCase
{
    /**
     * testCreateChannel.
     *
     */
    public function testCreateChannel(): void
    {
        $data = [
            'name' => fake()->name,
            'is_default' => true,
        ];
        $this->graphQL('
            mutation($data: CreateChannelInput!) {
                createChannel(input: $data)
                {
                    id
                    name,
                    is_default
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createChannel' => $data]
        ]);
    }

    /**
     * testGetChannels.
     *
     */
    public function testGetChannels(): void
    {
        $response = $this->graphQL('
            query {
                channels {
                    data {
                        id,
                        name,
                        is_default
                    }
                }
            }');

        $this->assertArrayHasKey('id', $response->json()['data']['channels']['data'][0]);
    }

    /**
     * testUpdateChannel.
     *
     */
    public function testUpdateChannel(): void
    {
        $data = [
            'name' => fake()->name,
            'is_default' => true,
        ];
        $newChannel = $this->graphQL('
            mutation($data: CreateChannelInput!) {
                createChannel(input: $data)
                {
                    id
                    name,
                    is_default
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createChannel' => $data]
        ]);
        $channelId = $newChannel['data']['createChannel']['id'];

        $this->graphQL('
        query($id: Mixed!) {
            channels(where: {column: ID, operator: EQ, value: $id}) {
                data {
                    id,
                    name,
                    is_default
                }
            }
        }', ['id' => $channelId])->assertJson([
            'data' => ['channels' => ['data' => [$data]]]
        ]);

        $data = [
            'name' => fake()->name,
        ];
        $this->graphQL('
            mutation($channelId: ID!, $data: UpdateChannelInput!) {
                updateChannel(id: $channelId, input: $data)
                {
                    name
                }
            }', ['channelId' => $channelId, 'data' => $data])->assertJson([
            'data' => ['updateChannel' => $data]
        ]);
    }

    /**
     * testDeleteChannel.
     *
     */
    public function testDeleteChannel(): void
    {
        $data = [
            'name' => fake()->name,
            'is_default' => false,
        ];
        $newChannel = $this->graphQL('
            mutation($data: CreateChannelInput!) {
                createChannel(input: $data)
                {
                    id
                    name,
                    is_default
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createChannel' => $data]
        ]);

        $channelId = $newChannel['data']['createChannel']['id'];

        $this->graphQL('
        query($id: Mixed!) {
            channels(where: {column: ID, operator: EQ, value: $id}) {
                data {
                    id,
                    name,
                    is_default
                }
            }
        }', ['id' => $channelId])->assertJson([
            'data' => ['channels' => ['data' => [$data]]]
        ]);

        $this->graphQL('
            mutation($id: ID!) {
                deleteChannel(id: $id)
            }', ['id' => $channelId])->assertJson([
            'data' => ['deleteChannel' => true]
        ]);
    }

    /**
     * testUnpublishProducts.
     *
     */
    public function testUnpublishProductsFromChannel(): void
    {
        $data = [
            'name' => fake()->name,
            'is_default' => true,
        ];
        $newChannel = $this->graphQL('
            mutation($data: CreateChannelInput!) {
                createChannel(input: $data)
                {
                    id
                    name,
                    is_default
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createChannel' => $data]
        ]);
        $channelId = $newChannel['data']['createChannel']['id'];
        $this->graphQL('
        query($id: Mixed!) {
            channels(where: {column: ID, operator: EQ, value: $id}) {
                data {
                    id,
                    name,
                    is_default
                }
            }
        }', ['id' => $channelId])->assertJson([
            'data' => ['channels' => ['data' => [$data]]]
        ]);

        $this->graphQL('
            mutation($id: ID!) {
                unPublishAllVariantsFromChannel(id: $id)
            }', ['id' => $channelId])->assertJson([
            'data' => ['unPublishAllVariantsFromChannel' => false] //doesn't have any product
        ]);
    }
}
