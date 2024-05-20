<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Tests\TestCase;

class RemoveVariantsToWarehouseTest extends TestCase
{
    /**
     * testRemoveVariantToWarehouse.
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
            'description' => fake()->text,
            'sku' => fake()->time
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
            'products_id' => $productId,
            'sku' => fake()->time,
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


        $data = [
            'price' => rand(1, 1000),
            'quantity' => rand(1, 5),
            'position' => rand(1, 4),
        ];
        $warehouseResponse = $this->graphQL('
        mutation($data: VariantsWarehousesInput! $id: ID! $warehouse_id: ID!) {
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
        $this->graphQL('
        mutation($id: ID! $warehouse_id: ID!) {
            removeVariantToWarehouse(id: $id warehouse_id: $warehouse_id)
        }', [
            'id' => $variantId,
            'warehouse_id' => $warehouseData['id']
        ]);
        $this->assertArrayHasKey('data', $response->json());
    }
}
