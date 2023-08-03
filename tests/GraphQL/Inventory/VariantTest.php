<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Tests\TestCase;

class VariantTest extends TestCase
{
    /**
     * testUpdateVariant.
     *
     * @return void
     */
    public function testUpdateVariant(): void
    {
        $region = [
            'name' => 'Test Region',
            'slug' => 'test-region',
            'short_slug' => 'test-region',
            'is_default' => 1,
            'currency_id' => 1,
        ];
        $regionResponse = $this->graphQL('
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
            'data' => $region
        ])->assertJson([
            'data' => ['createRegion' => $region]
        ]);
        $regionResponse = $regionResponse->decodeResponseJson();

        $warehouseData = [
            'regions_id' => $regionResponse['data']['createRegion']['id'],
            'name' => fake()->name,
            'location' => 'Test Location',
            'is_default' => false,
            'is_published' => 1,
        ];

        $warehouseResponse = $this->graphQL('
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
        }', ['data' => $warehouseData])->assertJson([
            'data' => ['createWarehouse' => $warehouseData]
        ]);

        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
        ];
        $this->graphQL('
        mutation($data: ProductInput!) {
            createProduct(input: $data)
            {
                name
                description
            }
        }', ['data' => $data])->assertJson([
            'data' => ['createProduct' => $data]
        ]);

        $response = $this->graphQL('
        query {
            products {
                data {
                    id
                    name
                    description
                }
            }
        }');
        $this->assertArrayHasKey('data', $response->json());
        $this->assertArrayHasKey('products', $response->json()['data']);
        $this->assertArrayHasKey('data', $response->json()['data']['products']);
        $this->assertArrayHasKey('id', $response->json()['data']['products']['data'][0]);

        $id = $response->json()['data']['products']['data'][0]['id'];

        $warehouseData = [
            'id' => $warehouseResponse['data']['createWarehouse']['id'],
        ];

        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
            'products_id' => $id,
            'warehouse' => $warehouseData
        ];
        $variantResponse = $this->graphQL('
        mutation($data: VariantsInput!) {
            createVariant(input: $data)
            {
                id
                name
                description
                products_id
            }
        }', ['data' => $data]);

        $this->assertArrayHasKey('id', $variantResponse->json()['data']['createVariant']);

        $id = $variantResponse->json()['data']['createVariant']['id'];
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
        ];
        $this->graphQL('
        mutation($id: ID! $data: VariantsUpdateInput!) {
            updateVariant(id: $id, input: $data)
            {
                id
                name
                description
            }
        }', ['id' => $id, 'data' => $data])->assertJson([
            'data' => ['updateVariant' => $data]
        ]);
    }

    /**
     * testAddVariantToWarehouse.
     *
     * @return void
     */
    public function testAddVariantToWarehouse(): void
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
        $warehouseData = [
            'id' => $response->json()['data']['createWarehouse']['id'],
        ];

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
            'products_id' => $productId,
            'warehouse' => $warehouseData
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
        }', ['data' => $data]);
        $variantId = $response->json()['data']['createVariant']['id'];

        $warehouseDataUpdate = [
            'regions_id' => $idRegion,
            'name' => fake()->name,
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
        }', ['data' => $warehouseDataUpdate])->assertJson([
            'data' => ['createWarehouse' => $warehouseDataUpdate]
        ]);
        $warehouseData = [
            'id' => $response->json()['data']['createWarehouse']['id'],
        ];
        $data = [
            'price' => rand(1, 1000),
            'quantity' => rand(1, 5),
            'position' => rand(1, 4),
        ];
        $warehouseResponse = $this->graphQL('
        mutation($data: VariantsWarehousesInput! $id: ID! $warehouse_id: Int!) {
            addVariantToWarehouse(input: $data id: $id warehouse_id: $warehouse_id)
            {
                id
                name
                description
                products_id
                warehouses{
                    warehouseinfo{
                        id
                    }
                }
            }
        }', [
            'data' => $data,
            'id' => $variantId,
            'warehouse_id' => $warehouseData['id']
        ]);

        $this->assertEquals(
            $warehouseData['id'],
            $warehouseResponse['data']['addVariantToWarehouse']['warehouses'][1]['warehouseinfo']['id']
        );
    }

    /**
     * testAddVariantToWarehouse.
     *
     * @return void
     */
    public function testUpdateVariantToWarehouse(): void
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
        $warehouseData = [
            'id' => $response->json()['data']['createWarehouse']['id'],
        ];

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
            'products_id' => $productId,
            'warehouse' => $warehouseData
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
        }', ['data' => $data]);
        $variantId = $response->json()['data']['createVariant']['id'];

        $data = [
            'price' => rand(1, 1000),
            'quantity' => rand(1, 5),
            'position' => rand(1, 4),
        ];
        $warehouseResponse = $this->graphQL('
        mutation($data: VariantsWarehousesInput! $id: ID! $warehouse_id: Int!) {
            updateVariantInWarehouse(input: $data id: $id warehouse_id: $warehouse_id)
            {
                id
                name
                description
                products_id
                warehouses{
                    price
                    warehouseinfo{
                        id
                    }
                }
            }
        }', [
            'data' => $data,
            'id' => $variantId,
            'warehouse_id' => $warehouseData['id']
        ]);

        $this->assertEquals(
            $data['price'],
            $warehouseResponse['data']['updateVariantInWarehouse']['warehouses'][0]['price']
        );
    }

}
