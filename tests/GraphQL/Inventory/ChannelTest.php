<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Channels\Actions\UnPublishAllVariantsAction;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class ChannelTest extends TestCase
{
    use InventoryCases;
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
            'data' => ['unPublishAllVariantsFromChannel' => true] // job dispatched to queue
        ]);
    }

    public function testUnPublishAllVariantsAction(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $this->setupInventory($app, $company, $user);

        $productData = new Product(
            app: $app,
            company: $company,
            user: $user,
            name: 'Unpublish Test ' . fake()->word(),
            sku: fake()->unique()->word(),
            warehouses: [[
                'quantity' => 5,
                'price' => 10.00,
            ]]
        );
        $product = (new CreateProductAction($productData, $user))->execute();
        $variant = $product->variants()->first();

        $warehouse = Warehouses::fromApp($app)->fromCompany($company)->first();
        $channel = Channels::fromApp($app)->fromCompany($company)->first();

        // Ensure variant has warehouse record, then add to channel
        $variant->updatePriceInWarehouse($warehouse, 10.00);
        $variant->updatePriceInChannel($channel, 10.00);

        // Verify it's published in the channel
        $channelRecord = VariantsChannels::where('channels_id', $channel->getId())
            ->where('is_published', 1)
            ->whereHas('variant', fn ($q) => $q->where('id', $variant->getId()))
            ->first();
        $this->assertNotNull($channelRecord);

        // Run the action
        new UnPublishAllVariantsAction($channel)->execute();

        // Verify it's unpublished (re-query since composite PK doesn't support refresh)
        $updatedRecord = VariantsChannels::where('channels_id', $channel->getId())
            ->whereHas('variant', fn ($q) => $q->where('id', $variant->getId()))
            ->first();
        $this->assertNotNull($updatedRecord);
        $this->assertEquals(0, (int) $updatedRecord->is_published);
    }
}
