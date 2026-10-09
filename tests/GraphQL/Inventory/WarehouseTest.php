<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Illuminate\Support\Str;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;
use Tests\Traits\AssertsIsDefaultOrdering;

class WarehouseTest extends TestCase
{
    use AssertsIsDefaultOrdering;
    use InventoryCases;

    /**
     * testCreateWarehouse.
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
            ],
        ])->assertJson([
            'data' => ['updateWarehouse' => [
                'id' => $warehouseResponse['id'],
                'regions_id' => $warehouseResponse['regions_id'],
                'name' => 'Test Warehouse Updated',
                'location' => 'Test Location Updated',
                'is_default' => true,
                'is_published' => 0,
            ]],
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
            'data' => ['deleteWarehouse' => true],
        ]);
    }

    public function testWarehousesOrderByIsDefault(): void
    {
        $regionId = $this->createRegion()->json('data.createRegion.id');
        $suffix = Str::uuid()->toString();

        $defaultId = (int) $this->graphQLData(
            $this->createWarehouses($regionId, [
                'regions_id' => $regionId,
                'name' => 'Default Sort Warehouse ' . $suffix,
                'location' => 'Test Location',
                'is_default' => true,
                'is_published' => true,
            ]),
            'createWarehouse'
        )['id'];
        $nonDefaultId = (int) $this->graphQLData(
            $this->createWarehouses($regionId, [
                'regions_id' => $regionId,
                'name' => 'Plain Sort Warehouse ' . $suffix,
                'location' => 'Test Location',
                'is_default' => false,
                'is_published' => true,
            ]),
            'createWarehouse'
        )['id'];

        $query = '
            query($ids: Mixed!, $order: SortOrder!) {
                warehouses(
                    where: {column: ID, operator: IN, value: $ids}
                    orderBy: [{column: IS_DEFAULT, order: $order}]
                ) {
                    data { id is_default }
                }
            }
        ';

        $this->assertOrdersByIsDefault(
            $query,
            'data.warehouses.data',
            $defaultId,
            $nonDefaultId
        );
    }
}
