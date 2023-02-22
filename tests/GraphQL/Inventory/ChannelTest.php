<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Tests\TestCase;

class ChannelTest extends TestCase
{
    /**
     * testCreateChannel.
     *
     * @return void
     */
    public function testCreateChannel(): void
    {
        $data = [
            'name' => fake()->name,
        ];
        $this->graphQL('
            mutation($data: CreateChannelInput!) {
                createChannel(input: $data)
                {
                    id
                    name
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createChannel' => $data]
        ]);
    }

    /**
     * testGetChannels.
     *
     * @return void
     */
    public function testGetChannels(): void
    {
        $data = [
            'name' => fake()->name,
        ];
        $this->graphQL('
            mutation($data: CreateChannelInput!) {
                createChannel(input: $data)
                {
                    id
                    name
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createChannel' => $data]
        ]);
        $this->graphQL('
            query {
                channels {
                    data {
                        id,
                        name
                    }
                }
            }')->assertJson([
            'data' => ['channels' => ['data' => [$data]]]
        ]);
    }

    /**
     * testUpdateChannel.
     *
     * @return void
     */
    public function testUpdateChannel(): void
    {
        $data = [
            'name' => fake()->name,
        ];
        $this->graphQL('
            mutation($data: CreateChannelInput!) {
                createChannel(input: $data)
                {
                    id
                    name
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createChannel' => $data]
        ]);
        $response = $this->graphQL('
        query {
            channels {
                data {
                    id,
                    name
                }
            }
        }')->assertJson([
            'data' => ['channels' => ['data' => [$data]]]
        ]);
        $id = $response['data']['channels']['data'][0]['id'];
        $data = [
            'name' => fake()->name,
        ];
        $this->graphQL('
            mutation($id: Int!, $data: UpdateChannelInput!) {
                updateChannel(id: $id, input: $data)
                {
                    name
                }
            }', ['id' => $id, 'data' => $data])->assertJson([
            'data' => ['updateChannel' => $data]
        ]);
    }

    /**
     * testDeleteChannel.
     *
     * @return void
     */
    public function testDeleteChannel(): void
    {
        $data = [
            'name' => fake()->name,
        ];
        $this->graphQL('
            mutation($data: CreateChannelInput!) {
                createChannel(input: $data)
                {
                    id
                    name
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createChannel' => $data]
        ]);
        $response = $this->graphQL('
        query {
            channels {
                data {
                    id,
                    name
                }
            }
        }')->assertJson([
            'data' => ['channels' => ['data' => [$data]]]
        ]);
        $id = $response['data']['channels']['data'][0]['id'];
        $this->graphQL('
            mutation($id: Int!) {
                deleteChannel(id: $id)
            }', ['id' => $id])->assertJson([
            'data' => ['deleteChannel' => true]
        ]);
    }

    /**
     * testUnpublishProducts.
     *
     * @return void
     */
    public function testUnpublishProductsFromChannel(): void
    {
        $data = [
            'name' => fake()->name,
        ];
        $this->graphQL('
            mutation($data: CreateChannelInput!) {
                createChannel(input: $data)
                {
                    id
                    name
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createChannel' => $data]
        ]);
        $response = $this->graphQL('
        query {
            channels {
                data {
                    id,
                    name
                }
            }
        }')->assertJson([
            'data' => ['channels' => ['data' => [$data]]]
        ]);
        $id = $response['data']['channels']['data'][0]['id'];

        $this->graphQL('
            mutation($id: Int!) {
                unPublishAllVariantsFromChannel(id: $id)
            }', ['id' => $id])->assertJson([
            'data' => ['unPublishAllVariantsFromChannel']
        ]);
    }
}
