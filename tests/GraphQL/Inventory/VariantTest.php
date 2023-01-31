<?php
declare(strict_types=1);
namespace Tests\GraphQL\Inventory;

use Tests\TestCase;

class VariantTest extends TestCase
{
    /**
     * testUpdateVariant
     *
     * @return void
     */
    public function testUpdateVariant(): void
    {
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
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
            'products_id' => $id
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
        $id = $response->json()['data']['createVariant']['id'];
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
        ];
        $this->graphQL('
        mutation($id: Int! $data: VariantsUpdateInput!) {
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
     * testAddVariantToWarehouse
     *
     * @return void
     */
    public function testAddVariantToWarehouse():void
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
        $warehouseId = $response->json()['data']['createWarehouse']['id'];
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
        $data = [

            'price' => rand(1, 1000),
            'quantity' => rand(1, 5),
            'position' => rand(1, 4),
        ];
        $this->graphQL('
        mutation($data: VariantsWarehousesInput! $id: Int! $warehouse_id: Int!) {
            addVariantToWarehouse(input: $data id: $id warehouse_id: $warehouse_id)
            {
                id
                name
                description
                products_id
            }
        }', [
            'data' => $data,
            'id' => $variantId,
            'warehouse_id' => $warehouseId
        ]);
        $this->assertArrayHasKey('data', $response->json());
    }
}
