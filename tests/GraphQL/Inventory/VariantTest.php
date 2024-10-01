<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class VariantTest extends TestCase
{
    use InventoryCases;
    /**
     * testUpdateVariant.
     *
     * @return void
     */
    public function testUpdateVariant(): void
    {
        $regionResponse = $this->createRegion();
        $this->assertArrayHasKey('id', $regionResponse['data']['createRegion']);
        $regionResponse = $regionResponse->json()['data']['createRegion'];

        $warehouseResponse = $this->createWarehouses($regionResponse['id']);
        $this->assertArrayHasKey('id', $warehouseResponse['data']['createWarehouse']);
        $warehouseResponse = $warehouseResponse->json()['data']['createWarehouse'];

        $productResponse = $this->createProduct();
        $this->assertArrayHasKey('id', $productResponse['data']['createProduct']);
        $id = $productResponse->json()['data']['createProduct']['id'];

        $warehouseData = [
            'id' => $warehouseResponse['id'],
        ];

        $variantResponse = $this->createVariant(
            productId: $id,
            warehouseData: $warehouseData
        );

        $this->assertArrayHasKey('id', $variantResponse->json()['data']['createVariant']);

        $variantResponse = $variantResponse->json()['data']['createVariant'];
        $data = [
            'name' => fake()->name,
            'description' => fake()->text,
            'sku' => $variantResponse['sku']
        ];
        $this->graphQL('
        mutation($id: ID! $data: VariantsUpdateInput!) {
            updateVariant(id: $id, input: $data)
            {
                id
                name
                sku
                description
            }
        }', ['id' => $variantResponse['id'], 'data' => $data])->assertJson([
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
        $regionResponse = $this->createRegion();
        $this->assertArrayHasKey('id', $regionResponse['data']['createRegion']);
        $regionResponse = $regionResponse->json()['data']['createRegion'];

        $warehouseResponse = $this->createWarehouses($regionResponse['id']);
        $this->assertArrayHasKey('id', $warehouseResponse['data']['createWarehouse']);
        $warehouseResponse = $warehouseResponse->json()['data']['createWarehouse'];

        $productResponse = $this->createProduct();
        $this->assertArrayHasKey('id', $productResponse['data']['createProduct']);
        $id = $productResponse->json()['data']['createProduct']['id'];

        $warehouseData = [
            'id' => $warehouseResponse['id'],
        ];

        $variantResponse = $this->createVariant(
            productId: $id,
            warehouseData: $warehouseData
        );

        $this->assertArrayHasKey('id', $variantResponse->json()['data']['createVariant']);
        $variantResponse = $variantResponse->json()['data']['createVariant'];

        $warehouseDataUpdate = [
            'regions_id' => $regionResponse['id'],
            'name' => fake()->name,
            'location' => 'Test Location',
            'is_default' => true,
            'is_published' => true,
        ];
        $newWarehouseResponse = $this->createWarehouses($regionResponse['id'], $warehouseDataUpdate);
        $this->assertArrayHasKey('id', $newWarehouseResponse['data']['createWarehouse']);
        $newWarehouseResponse = $newWarehouseResponse->json()['data']['createWarehouse'];

        $warehouseData = [
            'id' => $newWarehouseResponse['id'],
        ];
        $data = [
            'id' => $newWarehouseResponse['id'],
            'price' => rand(1, 1000),
            'quantity' => rand(1, 5),
            'position' => rand(1, 4),
        ];
        $warehouseResponse = $this->graphQL('
        mutation($data: WarehouseReferenceInput! $id: ID!) {
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
            'id' => $variantResponse['id'],
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
        $regionResponse = $this->createRegion();
        $this->assertArrayHasKey('id', $regionResponse['data']['createRegion']);
        $regionResponse = $regionResponse->json()['data']['createRegion'];

        $warehouseResponse = $this->createWarehouses($regionResponse['id']);
        $this->assertArrayHasKey('id', $warehouseResponse['data']['createWarehouse']);
        $warehouseResponse = $warehouseResponse->json()['data']['createWarehouse'];

        $productResponse = $this->createProduct();
        $this->assertArrayHasKey('id', $productResponse['data']['createProduct']);
        $id = $productResponse->json()['data']['createProduct']['id'];

        $warehouseData = [
            'id' => $warehouseResponse['id'],
        ];

        $variantResponse = $this->createVariant(
            productId: $id,
            warehouseData: $warehouseData
        );

        $this->assertArrayHasKey('id', $variantResponse->json()['data']['createVariant']);
        $variantResponse = $variantResponse->json()['data']['createVariant'];

        $data = [
            'id' => $warehouseData['id'],
            'price' => rand(1, 1000),
            'quantity' => rand(1, 5),
            'position' => rand(1, 4),
        ];
        $warehouseResponse = $this->graphQL('
        mutation($data: WarehouseReferenceInput! $id: ID!) {
            updateVariantInWarehouse(input: $data id: $id)
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
            'id' => $variantResponse['id']
        ]);

        $this->assertEquals(
            $data['price'],
            $warehouseResponse['data']['updateVariantInWarehouse']['warehouses'][0]['price']
        );
    }
}
