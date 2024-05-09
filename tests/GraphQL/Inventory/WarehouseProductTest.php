<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Tests\TestCase;

class WarehouseProductTest extends TestCase
{
    public function testWarehouseProduct(): void
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
        $warehouseId = $response->decodeResponseJson()['data']['createWarehouse']['id'];

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

        $productId = $response->decodeResponseJson()['data']['createProduct']['id'];
        $this->graphQL(
            '
                mutation addWarehouse($id: ID! $warehouse_id: ID!) {
                    addWarehouse(id: $id, warehouse_id: $warehouse_id)
                    {
                        id
                    }
                }
            ',
            [
                'id' => $productId,
                'warehouse_id' => $warehouseId
            ]
        )->assertJson([
            'data' => ['addWarehouse' => ['id' => $productId]]
        ]);
    }
}
