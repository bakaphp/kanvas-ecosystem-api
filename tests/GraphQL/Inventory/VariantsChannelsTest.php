<?php
declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Tests\TestCase;

class VariantsChannelsTest extends TestCase
{
    /**
     * testVariantToChannel.
     *
     * @return void
     */
    public function testVariantToChannel(): void
    {
        $data = [
            'name' => 'Test Region',
            'slug' => 'test-region',
            'short_slug' => 'test-region',
            'is_default' => 1,
            'currency_id' => 1,
        ];
        $response = $this->graphQL('
            mutation($data: RegionInput!) {
                createRegion(input: $data)
                {
                    id
                    name
                    slug
                    short_slug
                    currency_id
                    is_default
                }
            }
        ', [
            'data' => $data
        ])->assertJson([
            'data' => ['createRegion' => $data]
        ]);

        $response = $response->decodeResponseJson();

        $data = [
            'regions_id' => $response['data']['createRegion']['id'],
            'name' => 'Test Warehouse',
            'location' => 'Test Location',
            'is_default' => false,
            'is_published' => 1,
        ];

        $response = $this->graphQL('
            mutation($data: WarehouseInput!) {
                createWarehouse(input: $data)
                {
                    id
                    regions_id
                    name
                    location
                    is_default
                    is_published
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createWarehouse' => $data]
        ]);
        $warehousesId = $response->json()['data']['createWarehouse']['id'];
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
        ];
        $response = $this->graphQL('
        mutation($data: ProductInput!) {
            createProduct(input: $data)
            {
                id
                name
                description
            }
        }', ['data' => $data])->assertJson([
            'data' => ['createProduct' => $data]
        ]);
        $productId = $response->json()['data']['createProduct']['id'];
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
            'products_id' => $productId
        ];
        $response = $this->graphQL('
        mutation($data: VariantsInput!) {
            createVariant(input: $data)
            { 
                id
                name
                description
                products_id
            }
        }', ['data' => $data])->assertJson([
            'data' => ['createVariant' => $data]
        ]);
        $variantId = $response->json()['data']['createVariant']['id'];

        $dataChannel = [
            'name' => fake()->name,
            'description' => fake()->text,
        ];

        $response = $this->graphQL('
        mutation($data: CreateChannelInput!) {
            createChannel(input: $data)
            {
                id
                name
                description
            }
        }', ['data' => $dataChannel]);

        $response->assertJson([
            'data' => ['createChannel' => $dataChannel]
        ]);

        $channelId = $response->json()['data']['createChannel']['id'];
        $response = $this->graphQL(
            '
        mutation addVariantToChannel($id: Int! $channels_id: Int! $warehouses_id: Int! $input: VariantChannelInput!){
            addVariantToChannel(id: $id channels_id:$channels_id warehouses_id:$warehouses_id input:$input){
                id
            } 
        }
        ',
            [
                'id' => $variantId,
                'channels_id' => $channelId,
                'warehouses_id' => $warehousesId,
                'input' => [
                    'price' => 100,
                    'discounted_price' => 10,
                    'is_published' => 1,
                    'is_published' => false
                ]
            ]
        );
        $response->assertJson([
            'data' => ['addVariantToChannel' => ['id' => $variantId]]
        ]);

        $response = $this->graphQL(
            'mutation ($id: Int! $channels_id: Int!) {
                removeVariantChannel(id: $id channels_id: $channels_id)
                {
                    id
                }
            }',
            [
                'id' => $variantId,
                'channels_id' => $channelId
            ]
        );
        $response->assertJson([
            'data' => ['removeVariantChannel' => ['id' => $variantId]]
        ]);
    }
}
