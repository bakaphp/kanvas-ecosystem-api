<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Tests\TestCase;

class WarehouseTest extends TestCase
{
    /**
     * testCreateWarehouse.
     *
     * @return void
     */
    public function testCreateWarehouse(): void
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
            'is_default' => true,
            'is_published' => true,
        ];

        $response = $this->graphQL('
            mutation($data: WarehouseInput!) {
                createWarehouse(input: $data)
                {
                    regions_id
                    name
                    location
                    is_default
                    is_published
                }
            }', ['data' => $data])->assertJson([
            'data' => ['createWarehouse' => $data]
        ]);
    }

    /**
     * testFindWarehouse.
     *
     * @return void
     */
    public function testFindWarehouse(): void
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
        $warehouseId = $response['data']['createWarehouse']['id'];

        $this->graphQL(
            '
            query getWarehouses($id: Mixed!) {
                getWarehouses(where: {column: ID, operator: EQ, value: $id}){
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
        ,['id' =>$warehouseId])->assertJson([
            'data' => ['getWarehouses' => ['data' => [$response->decodeResponseJson()['data']['createWarehouse']]]]
        ]);
    }

    /**
     * testUpdateWareHouse.
     *
     * @return void
     */
    public function testUpdateWarehouse(): void
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
        $data = $response->decodeResponseJson()['data']['createWarehouse'];
        $this->graphQL('
            mutation($id: Int!, $data: WarehouseInputUpdate!) {
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
            'id' => $data['id'],
            'data' => [
                'regions_id' => $data['regions_id'],
                'name' => 'Test Warehouse Updated',
                'location' => 'Test Location Updated',
                'is_default' => true,
                'is_published' => 0,
            ]
        ])->assertJson([
            'data' => ['updateWarehouse' => [
                'id' => $data['id'],
                'regions_id' => $data['regions_id'],
                'name' => 'Test Warehouse Updated',
                'location' => 'Test Location Updated',
                'is_default' => true,
                'is_published' => 0,
            ]]
        ]);
    }

    public function testDeleteWarehouse(): void
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
        $data = $response->decodeResponseJson()['data']['createWarehouse'];
        $this->graphQL('
            mutation($id: Int!) {
                deleteWarehouse(id: $id)
            }', [
            'id' => $data['id'],
        ])->assertJson([
            'data' => ['deleteWarehouse' => true]
        ]);
    }
}
