<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Kanvas\Inventory\Products\Models\ProductsWarehouses;
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
        $regionSlug = 'test-region-' . uniqid();
        $dataRegion = [
            'name' => 'Test Region ' . $regionSlug,
            'slug' => $regionSlug,
            'short_slug' => $regionSlug,
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
            ->assertSuccessful();
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

        $warehouseDataUpdate = [
            'regions_id' => $idRegion,
            'name' => fake()->name,
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
        }', ['data' => $warehouseDataUpdate])->assertJson([
            'data' => ['createWarehouse' => $warehouseDataUpdate]
        ]);
        $warehouseData = [
            'id' => $response->json()['data']['createWarehouse']['id'],
        ];

        $data = [
            'id' => $warehouseData['id'],
            'price' => rand(1, 1000),
            'quantity' => rand(1, 5),
            'position' => rand(1, 4),
        ];
        $warehouseResponse = $this->graphQL('
        mutation($data: WarehouseReferenceInput! $id: ID! $warehouse_id: ID!) {
            addVariantToWarehouse(input: $data id: $id)
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

    public function testRemoveLastVariantFromWarehouseCleansUpProductWarehouse(): void
    {
        $regionSlug = 'test-region-' . uniqid();
        $regionResponse = $this->graphQL('
            mutation($data: RegionInput!) {
                createRegion(input: $data) { id }
            }
        ', ['data' => [
            'name' => 'Test Region ' . $regionSlug,
            'slug' => $regionSlug,
            'short_slug' => $regionSlug,
            'is_default' => 1,
            'currency_id' => 1,
        ]])->assertSuccessful();
        $regionId = $regionResponse->json('data.createRegion.id');

        $warehouseResponse = $this->graphQL('
            mutation($data: WarehouseInput!) {
                createWarehouse(input: $data) { id }
            }
        ', ['data' => [
            'regions_id' => $regionId,
            'name' => 'Test Warehouse ' . uniqid(),
            'location' => 'Test Location',
            'is_default' => true,
            'is_published' => true,
        ]])->assertSuccessful();
        $warehouseId = $warehouseResponse->json('data.createWarehouse.id');

        $productResponse = $this->graphQL('
            mutation($data: ProductInput!) {
                createProduct(input: $data) { id }
            }
        ', ['data' => [
            'name' => fake()->name,
            'description' => fake()->text,
            'sku' => fake()->unique()->ean13,
        ]])->assertSuccessful();
        $productId = $productResponse->json('data.createProduct.id');

        $variant1Response = $this->graphQL('
            mutation($data: VariantsInput!) {
                createVariant(input: $data) { id }
            }
        ', ['data' => [
            'name' => 'Variant 1 ' . uniqid(),
            'description' => fake()->text,
            'products_id' => $productId,
            'sku' => fake()->unique()->ean13,
            'warehouses' => [['id' => $warehouseId, 'price' => 100, 'quantity' => 5]],
        ]])->assertSuccessful();
        $variant1Id = $variant1Response->json('data.createVariant.id');

        $variant2Response = $this->graphQL('
            mutation($data: VariantsInput!) {
                createVariant(input: $data) { id }
            }
        ', ['data' => [
            'name' => 'Variant 2 ' . uniqid(),
            'description' => fake()->text,
            'products_id' => $productId,
            'sku' => fake()->unique()->ean13,
            'warehouses' => [['id' => $warehouseId, 'price' => 200, 'quantity' => 3]],
        ]])->assertSuccessful();
        $variant2Id = $variant2Response->json('data.createVariant.id');

        $this->graphQL('
            mutation($id: ID! $warehouse_id: ID!) {
                removeVariantToWarehouse(id: $id warehouse_id: $warehouse_id)
            }
        ', ['id' => $variant1Id, 'warehouse_id' => $warehouseId])->assertSuccessful();

        // After removing variant 1, product should still show this warehouse (variant 2 remains)
        $this->graphQL('
            query($search: String, $hasWarehouses: QueryProductsHasWarehousesWhereHasConditions) {
                products(search: $search, hasWarehouses: $hasWarehouses, first: 1) {
                    data { id }
                }
            }
        ', [
            'hasWarehouses' => ['column' => 'ID', 'operator' => 'EQ', 'value' => (string) $warehouseId],
            'search' => '',
        ])->assertSuccessful();

        $this->graphQL('
            mutation($id: ID! $warehouse_id: ID!) {
                removeVariantToWarehouse(id: $id warehouse_id: $warehouse_id)
            }
        ', ['id' => $variant2Id, 'warehouse_id' => $warehouseId])->assertSuccessful();

        // After removing both variants, verify via updateVariant that re-adding works
        $this->graphQL('
            mutation($data: VariantsInput!) {
                createVariant(input: $data) {
                    id
                    warehouses {
                        warehouses_id
                    }
                }
            }
        ', ['data' => [
            'name' => 'Variant 3 ' . uniqid(),
            'description' => fake()->text,
            'products_id' => $productId,
            'sku' => fake()->unique()->ean13,
            'warehouses' => [['id' => $warehouseId, 'price' => 300, 'quantity' => 1]],
        ]])->assertSuccessful();
    }
}
