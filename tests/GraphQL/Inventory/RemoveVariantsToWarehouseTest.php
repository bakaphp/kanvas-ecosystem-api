<?php
declare(strict_types=1);
namespace Tests\GraphQL\Inventory;

use Tests\TestCase;

class RemoveVariantsToWarehouseTest extends TestCase
{
    /**
     * testRemoveVariantToWarehouse
     *
     * @return vod
     */
    public function testRemoveVariantToWarehouse(): void
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

            'price' => 10.00,
            'quantity' => 1,
            'position' => 1,
        ];
        $response = $this->graphQL('
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
        $this->graphQL('
        mutation($id: Int! $warehouse_id: Int!) {
            removeVariantToWarehouse(id: $id warehouse_id: $warehouse_id)
        }', [
            'id' => $variantId,
            'warehouse_id' => $warehouseId
        ]);
        $this->assertArrayHasKey('data', $response->json());
    }
}
