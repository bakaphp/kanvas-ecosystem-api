<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class WarehouseTest extends TestCase
{
    use InventoryCases;

    /**
     * testCreateWarehouse.
     *
     * @return void
     */
    public function testCreateWarehouse(): void
    {
        $regionResponse = $this->createRegion();
        $this->assertArrayHasKey('id', $regionResponse['data']['createRegion']);
        $regionResponse = $regionResponse->json()['data']['createRegion'];

        $warehouseResponse = $this->createWarehouses($regionResponse['id']);
        $this->assertArrayHasKey('id', $warehouseResponse['data']['createWarehouse']);
        $warehouseResponse = $warehouseResponse->json()['data']['createWarehouse'];
    }

    /**
     * testFindWarehouse.
     *
     * @return void
     */
    public function testFindWarehouse(): void
    {
        $regionResponse = $this->createRegion();
        $this->assertArrayHasKey('id', $regionResponse['data']['createRegion']);
        $regionResponse = $regionResponse->json()['data']['createRegion'];

        $warehouseResponse = $this->createWarehouses($regionResponse['id']);
        $this->assertArrayHasKey('id', $warehouseResponse['data']['createWarehouse']);
        $warehouseResponse = $warehouseResponse->json()['data']['createWarehouse'];

        $warehouses = $this->graphQL(
            '
            query warehouses {
                warehouses(orderBy: [{ column: ID, order: DESC }]){
                    data {
                        id
                        regions_id
                        name
                        location
                        is_default
                        is_published
                    }
                }
            }
            '
        );

        $this->assertArrayHasKey('id', $warehouses->json()['data']['warehouses']['data'][0]);
    }

    /**
     * testUpdateWareHouse.
     *
     * @return void
     */
    public function testUpdateWarehouse(): void
    {
        $regionResponse = $this->createRegion();
        $this->assertArrayHasKey('id', $regionResponse['data']['createRegion']);
        $regionResponse = $regionResponse->json()['data']['createRegion'];

        $warehouseResponse = $this->createWarehouses($regionResponse['id']);
        $this->assertArrayHasKey('id', $warehouseResponse['data']['createWarehouse']);
        $warehouseResponse = $warehouseResponse->json()['data']['createWarehouse'];

        $this->graphQL('
            mutation($id: ID!, $data: WarehouseInputUpdate!) {
                updateWarehouse(id: $id, input: $data)
                {
                    id
                    regions_id
                    name
                    location
                    is_default
                    is_published
                }
            }', [
            'id' => $warehouseResponse['id'],
            'data' => [
                'regions_id' => $warehouseResponse['regions_id'],
                'name' => 'Test Warehouse Updated',
                'location' => 'Test Location Updated',
                'is_default' => true,
                'is_published' => 0,
            ]
        ])->assertJson([
            'data' => ['updateWarehouse' => [
                'id' => $warehouseResponse['id'],
                'regions_id' => $warehouseResponse['regions_id'],
                'name' => 'Test Warehouse Updated',
                'location' => 'Test Location Updated',
                'is_default' => true,
                'is_published' => 0,
            ]]
        ]);
    }

    public function testDeleteWarehouse(): void
    {
        $regionResponse = $this->createRegion();
        $this->assertArrayHasKey('id', $regionResponse['data']['createRegion']);
        $regionResponse = $regionResponse->json()['data']['createRegion'];

        $data = [
            'regions_id' => $regionResponse['id'],
            'name' => 'Test Warehouse',
            'location' => 'Test Location',
            'is_default' => false,
            'is_published' => true,
        ];

        $warehouseResponse = $this->createWarehouses($regionResponse['id'], $data);
        $this->assertArrayHasKey('id', $warehouseResponse['data']['createWarehouse']);
        $warehouseResponse = $warehouseResponse->json()['data']['createWarehouse'];

        $this->graphQL('
            mutation($id: ID!) {
                deleteWarehouse(id: $id)
            }', [
            'id' => $warehouseResponse['id'],
        ])->assertJson([
            'data' => ['deleteWarehouse' => true]
        ]);
    }
}
