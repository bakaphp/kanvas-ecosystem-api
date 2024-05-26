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
        $dataRegion = [
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
            }', ['data' => $dataRegion])
            ->assertJson([
                'data' => ['createRegion' => $dataRegion]
            ]);
        $idRegion = $response->json()['data']['createRegion']['id'];
        $data = [
            'regions_id' => $idRegion,
            'name' => 'Test Warehouse',
            'location' => 'Test Location',
            'is_default' => true,
            'is_published' => true,
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
        $warehouseData = [
            'id' => $response->json()['data']['createWarehouse']['id'],
        ];
        $data = [
            'name' => fake()->name,
            'sku' => fake()->time,
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
            }', ['data' => $data]);

        unset($data['sku']);
        $response->assertJson([
            'data' => ['createProduct' => $data],
        ]);
        $productId = $response->json()['data']['createProduct']['id'];
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
            'sku' => fake()->time,
            'products_id' => $productId,
            'warehouses' => [$warehouseData]
        ];
        $response = $this->graphQL('
        mutation($data: VariantsInput!) {
            createVariant(input: $data)
            { 
                id
                name
                sku
                description
                products_id
            }
        }', ['data' => $data]);
        $variantId = $response->json()['data']['createVariant']['id'];

        $dataChannel = [
            'name' => fake()->name,
            'description' => fake()->text,
            'is_default' => true,
        ];

        $response = $this->graphQL('
        mutation($data: CreateChannelInput!) {
            createChannel(input: $data)
            {
                id
                name
                description,
                is_default
            }
        }', ['data' => $dataChannel]);

        $response->assertJson([
            'data' => ['createChannel' => $dataChannel]
        ]);

        $channelId = $response->json()['data']['createChannel']['id'];
        $response = $this->graphQL(
            '
        mutation addVariantToChannel($variants_id: ID! $channels_id: ID! $warehouses_id: ID! $input: VariantChannelInput!){
            addVariantToChannel(variants_id: $variants_id channels_id:$channels_id warehouses_id:$warehouses_id input:$input){
                id
            } 
        }
        ',
            [
                'variants_id' => $variantId,
                'channels_id' => $channelId,
                'warehouses_id' => $warehouseData['id'],
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
            'mutation ($variants_id: ID! $channels_id: ID! $warehouses_id: ID!) {
                removeVariantChannel(variants_id: $variants_id channels_id: $channels_id warehouses_id: $warehouses_id)
                {
                    id
                }
            }',
            [
                'variants_id' => $variantId,
                'channels_id' => $channelId,
                'warehouses_id' => $warehouseData['id'],
            ]
        );
        $response->assertJson([
            'data' => ['removeVariantChannel' => ['id' => $variantId]]
        ]);
    }

    public function testUpdateVariantToChannel(): void
    {
        $dataRegion = [
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
            }', ['data' => $dataRegion])
            ->assertJson([
                'data' => ['createRegion' => $dataRegion]
            ]);
        $idRegion = $response->json()['data']['createRegion']['id'];
        $data = [
            'regions_id' => $idRegion,
            'name' => 'Test Warehouse',
            'location' => 'Test Location',
            'is_default' => true,
            'is_published' => true,
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
        $warehouseData = [
            'id' => $response->json()['data']['createWarehouse']['id'],
        ];
        $data = [
            'name' => fake()->name,
            'sku' => fake()->time,
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
            }', ['data' => $data]);

        unset($data['sku']);
        $response->assertJson([
            'data' => ['createProduct' => $data],
        ]);
        $productId = $response->json()['data']['createProduct']['id'];
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
            'sku' => fake()->time,
            'products_id' => $productId,
            'warehouses' => [$warehouseData]
        ];
        $response = $this->graphQL('
        mutation($data: VariantsInput!) {
            createVariant(input: $data)
            { 
                id
                name
                sku
                description
                products_id
            }
        }', ['data' => $data]);
        $variantId = $response->json()['data']['createVariant']['id'];

        $dataChannel = [
            'name' => fake()->name,
            'description' => fake()->text,
            'is_default' => true,
        ];

        $response = $this->graphQL('
        mutation($data: CreateChannelInput!) {
            createChannel(input: $data)
            {
                id
                name
                description,
                is_default
            }
        }', ['data' => $dataChannel]);

        $response->assertJson([
            'data' => ['createChannel' => $dataChannel]
        ]);

        $channelId = $response->json()['data']['createChannel']['id'];
        $response = $this->graphQL(
            '
        mutation addVariantToChannel($variants_id: ID! $channels_id: ID! $warehouses_id: ID! $input: VariantChannelInput!){
            addVariantToChannel(variants_id: $variants_id channels_id:$channels_id warehouses_id:$warehouses_id input:$input){
                id
            } 
        }
        ',
            [
                'variants_id' => $variantId,
                'channels_id' => $channelId,
                'warehouses_id' => $warehouseData['id'],
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
            '
        mutation addVariantToChannel($variants_id: ID! $channels_id: ID! $warehouses_id: ID! $input: VariantChannelInput!){
            updateVariantInChannel(variants_id: $variants_id channels_id:$channels_id warehouses_id:$warehouses_id input:$input){
                id
            } 
        }
        ',
            [
                'variants_id' => $variantId,
                'channels_id' => $channelId,
                'warehouses_id' => $warehouseData['id'],
                'input' => [
                    'price' => 344,
                    'discounted_price' => 120,
                    'is_published' => 1,
                    'is_published' => false
                ]
            ]
        );
        $response->assertJson([
            'data' => ['updateVariantInChannel' => ['id' => $variantId]]
        ]);

        $response = $this->graphQL(
            'mutation ($variants_id: ID! $channels_id: ID! $warehouses_id: ID!) {
                removeVariantChannel(variants_id: $variants_id channels_id: $channels_id warehouses_id: $warehouses_id)
                {
                    id
                }
            }',
            [
                'variants_id' => $variantId,
                'channels_id' => $channelId,
                'warehouses_id' => $warehouseData['id'],
            ]
        );
        $response->assertJson([
            'data' => ['removeVariantChannel' => ['id' => $variantId]]
        ]);
    }
}
