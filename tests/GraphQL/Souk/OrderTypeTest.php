<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderTypes;

class OrderTypeTest extends OrderBase
{
    public function testListOrderTypes(): void
    {
        OrderTypes::firstOrCreate([
            'name' => 'test-order-type',
            'apps_id' => $this->apps->id,
            'companies_id' => $this->company->id,
        ]);

        $response = $this->graphQL('
            query {
                orderTypes(first: 10) {
                    data {
                        id
                        name
                        total_statuses
                        created_at
                    }
                }
            }
        ', [], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $response->assertSuccessful();
        $data = $response->json('data.orderTypes.data');
        $this->assertNotEmpty($data);
        $this->assertArrayHasKey('id', $data[0]);
        $this->assertArrayHasKey('name', $data[0]);
    }

    public function testListOrderTypesWithWhereFilter(): void
    {
        $orderType = OrderTypes::firstOrCreate([
            'name' => 'filtered-type',
            'apps_id' => $this->apps->id,
            'companies_id' => $this->company->id,
        ]);

        $response = $this->graphQL('
            query($name: Mixed!) {
                orderTypes(
                    first: 10
                    where: { column: NAME, operator: EQ, value: $name }
                ) {
                    data {
                        id
                        name
                    }
                }
            }
        ', [
            'name' => 'filtered-type',
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $response->assertSuccessful();
        $data = $response->json('data.orderTypes.data');
        $this->assertNotEmpty($data);
        $this->assertEquals('filtered-type', $data[0]['name']);
    }

    public function testListOrderStatus(): void
    {
        $orderType = OrderTypes::firstOrCreate([
            'name' => 'status-list-type',
            'apps_id' => $this->apps->id,
            'companies_id' => $this->company->id,
        ]);

        OrderStatus::firstOrCreate([
            'order_types_id' => $orderType->id,
            'apps_id' => 0,
            'slug' => 'test-status',
            'name' => 'Test Status',
            'is_default' => true,
            'is_final' => false,
            'sequence' => 1,
        ]);

        $response = $this->graphQL('
            query {
                orderStatus(first: 10) {
                    data {
                        id
                        companies_id
                        name
                        slug
                        order_type_id
                        is_default
                        is_final
                        sequence
                    }
                }
            }
        ', [], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $response->assertSuccessful();
        $data = $response->json('data.orderStatus.data');
        $this->assertNotEmpty($data);
        $this->assertArrayHasKey('name', $data[0]);
        $this->assertArrayHasKey('slug', $data[0]);
        $this->assertArrayHasKey('companies_id', $data[0]);
    }

    public function testListOrderStatusWithWhereFilter(): void
    {
        $orderType = OrderTypes::firstOrCreate([
            'name' => 'filter-status-type',
            'apps_id' => $this->apps->id,
            'companies_id' => $this->company->id,
        ]);

        OrderStatus::firstOrCreate([
            'order_types_id' => $orderType->id,
            'apps_id' => 0,
            'slug' => 'filtered-status',
            'name' => 'Filtered Status',
            'is_default' => false,
            'is_final' => false,
            'sequence' => 1,
        ]);

        $response = $this->graphQL('
            query($name: Mixed!) {
                orderStatus(
                    first: 10
                    where: { column: NAME, operator: EQ, value: $name }
                ) {
                    data {
                        id
                        name
                        slug
                    }
                }
            }
        ', [
            'name' => 'Filtered Status',
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $response->assertSuccessful();
        $data = $response->json('data.orderStatus.data');
        $this->assertNotEmpty($data);
        $this->assertEquals('Filtered Status', $data[0]['name']);
    }

    public function testCreateOrderStatus(): void
    {
        $orderType = OrderTypes::firstOrCreate([
            'name' => 'crud-test-type',
            'apps_id' => $this->apps->id,
            'companies_id' => $this->company->id,
        ]);

        $response = $this->graphQL('
            mutation createOrderStatus($input: CreateOrderStatusInput!) {
                createOrderStatus(input: $input) {
                    id
                    name
                    slug
                    is_default
                    is_final
                    sequence
                }
            }
        ', [
            'input' => [
                'order_type_id' => $orderType->id,
                'name' => 'New Status',
                'slug' => 'new-status-' . uniqid(),
                'is_default' => false,
                'is_final' => false,
                'sequence' => 5,
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $response->assertSuccessful();
        $data = $response->json('data.createOrderStatus');
        $this->assertEquals('New Status', $data['name']);
        $this->assertEquals(5, $data['sequence']);
    }

    public function testUpdateOrderStatus(): void
    {
        $orderType = OrderTypes::firstOrCreate([
            'name' => 'update-test-type',
            'apps_id' => $this->apps->id,
            'companies_id' => $this->company->id,
        ]);

        $status = OrderStatus::firstOrCreate([
            'order_types_id' => $orderType->id,
            'apps_id' => $this->apps->id,
            'slug' => 'updatable-status',
            'name' => 'Updatable Status',
            'is_default' => false,
            'is_final' => false,
            'sequence' => 1,
        ]);

        $response = $this->graphQL('
            mutation updateOrderStatus($id: ID!, $input: UpdateOrderStatusInput!) {
                updateOrderStatus(id: $id, input: $input) {
                    id
                    name
                    sequence
                }
            }
        ', [
            'id' => $status->id,
            'input' => [
                'name' => 'Updated Status Name',
                'sequence' => 10,
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $response->assertSuccessful();
        $data = $response->json('data.updateOrderStatus');
        $this->assertEquals('Updated Status Name', $data['name']);
        $this->assertEquals(10, $data['sequence']);
    }

    public function testCreateOrderType(): void
    {
        $name = 'test-create-type-' . uniqid();

        $response = OrderTypes::withoutSyncingToSearch(fn () => $this->graphQL('
            mutation createOrderType($input: CreateOrderTypeInput!) {
                createOrderType(input: $input) {
                    id
                    name
                }
            }
        ', [
            'input' => [
                'name' => $name,
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]));

        $response->assertSuccessful();
        $data = $response->json('data.createOrderType');
        $this->assertEquals($name, $data['name']);
        $this->assertNotEmpty($data['id']);
    }

    public function testCreateOrderTypeDuplicateName(): void
    {
        $name = 'duplicate-type-' . uniqid();

        OrderTypes::withoutSyncingToSearch(fn () => OrderTypes::firstOrCreate([
            'name' => $name,
            'apps_id' => $this->apps->id,
            'companies_id' => $this->company->id,
        ]));

        $response = OrderTypes::withoutSyncingToSearch(fn () => $this->graphQL('
            mutation createOrderType($input: CreateOrderTypeInput!) {
                createOrderType(input: $input) {
                    id
                    name
                }
            }
        ', [
            'input' => [
                'name' => $name,
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]));

        $response->assertGraphQLErrorMessage('An order type with this name already exists');
    }

    public function testUpdateOrderType(): void
    {
        $orderType = OrderTypes::withoutSyncingToSearch(fn () => OrderTypes::firstOrCreate([
            'name' => 'updatable-order-type-' . uniqid(),
            'apps_id' => $this->apps->id,
            'companies_id' => $this->company->id,
        ]));

        $newName = 'updated-order-type-' . uniqid();

        $response = OrderTypes::withoutSyncingToSearch(fn () => $this->graphQL('
            mutation updateOrderType($id: ID!, $input: UpdateOrderTypeInput!) {
                updateOrderType(id: $id, input: $input) {
                    id
                    name
                }
            }
        ', [
            'id' => $orderType->id,
            'input' => [
                'name' => $newName,
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]));

        $response->assertSuccessful();
        $data = $response->json('data.updateOrderType');
        $this->assertEquals($newName, $data['name']);
    }

    public function testUpdateOrderTypeNotFound(): void
    {
        $response = $this->graphQL('
            mutation updateOrderType($id: ID!, $input: UpdateOrderTypeInput!) {
                updateOrderType(id: $id, input: $input) {
                    id
                    name
                }
            }
        ', [
            'id' => 999999,
            'input' => [
                'name' => 'nonexistent',
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $response->assertGraphQLErrorMessage('Order type not found');
    }

    public function testDeleteOrderType(): void
    {
        $orderType = OrderTypes::withoutSyncingToSearch(fn () => OrderTypes::firstOrCreate([
            'name' => 'deletable-order-type-' . uniqid(),
            'apps_id' => $this->apps->id,
            'companies_id' => $this->company->id,
        ]));

        $response = OrderTypes::withoutSyncingToSearch(fn () => $this->graphQL('
            mutation deleteOrderType($id: ID!) {
                deleteOrderType(id: $id)
            }
        ', [
            'id' => $orderType->id,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]));

        $response->assertSuccessful();
        $this->assertTrue($response->json('data.deleteOrderType'));
    }

    public function testDeleteOrderTypeWithOrders(): void
    {
        $orderType = OrderTypes::withoutSyncingToSearch(fn () => OrderTypes::firstOrCreate([
            'name' => 'type-with-orders-' . uniqid(),
            'apps_id' => $this->apps->id,
            'companies_id' => $this->company->id,
        ]));

        Order::withoutSyncingToSearch(fn () => Order::create([
            'apps_id' => $this->apps->id,
            'companies_id' => $this->company->id,
            'order_types_id' => $orderType->id,
            'region_id' => $this->region->id,
            'users_id' => $this->user->id,
            'people_id' => 0,
            'status' => 'draft',
            'is_deleted' => 0,
        ]));

        $response = OrderTypes::withoutSyncingToSearch(fn () => $this->graphQL('
            mutation deleteOrderType($id: ID!) {
                deleteOrderType(id: $id)
            }
        ', [
            'id' => $orderType->id,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]));

        $response->assertGraphQLErrorMessage('Cannot delete order type that has existing orders');
    }
}
