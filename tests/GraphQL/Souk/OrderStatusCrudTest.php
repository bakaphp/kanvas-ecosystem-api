<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Silber\Bouncer\BouncerFacade as Bouncer;

class OrderStatusCrudTest extends OrderBase
{
    public function setUp(): void
    {
        parent::setUp();

        $scope = RolesEnums::getScope($this->apps, global: true);
        Bouncer::scope()->to($scope);
        Bouncer::assign('Admins')->to($this->user);
        Bouncer::allow('Admins')->to(['create', 'edit', 'delete'], OrderStatus::class);
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

    public function testDeleteOrderStatus(): void
    {
        $orderType = OrderTypes::firstOrCreate([
            'name' => 'delete-status-test-type-' . uniqid(),
            'apps_id' => $this->apps->id,
            'companies_id' => $this->company->id,
        ]);

        $createResponse = $this->graphQL('
            mutation createOrderStatus($input: CreateOrderStatusInput!) {
                createOrderStatus(input: $input) {
                    id
                    name
                }
            }
        ', [
            'input' => [
                'order_type_id' => $orderType->id,
                'name' => 'Deletable Status',
                'slug' => 'deletable-status-' . uniqid(),
                'is_default' => false,
                'is_final' => false,
                'sequence' => 99,
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $createResponse->assertSuccessful();
        $id = $createResponse->json('data.createOrderStatus.id');

        $response = $this->graphQL('
            mutation deleteOrderStatus($id: ID!) {
                deleteOrderStatus(id: $id)
            }
        ', [
            'id' => $id,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $response->assertSuccessful();
        $this->assertTrue($response->json('data.deleteOrderStatus'));
    }

    public function testCreateOrderStatusWithTransitions(): void
    {
        $orderType = OrderTypes::firstOrCreate([
            'name' => 'transition-test-type-' . uniqid(),
            'apps_id' => $this->apps->id,
            'companies_id' => $this->company->id,
        ]);

        $firstSlug = 'first-status-' . uniqid();

        $this->graphQL('
            mutation createOrderStatus($input: CreateOrderStatusInput!) {
                createOrderStatus(input: $input) {
                    id
                    name
                    slug
                }
            }
        ', [
            'input' => [
                'order_type_id' => $orderType->id,
                'name' => 'First Status',
                'slug' => $firstSlug,
                'is_default' => true,
                'is_final' => false,
                'sequence' => 1,
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ])->assertSuccessful();

        $response = $this->graphQL('
            mutation createOrderStatus($input: CreateOrderStatusInput!) {
                createOrderStatus(input: $input) {
                    id
                    name
                    slug
                    sequence
                }
            }
        ', [
            'input' => [
                'order_type_id' => $orderType->id,
                'name' => 'Second Status',
                'slug' => 'second-status-' . uniqid(),
                'is_default' => false,
                'is_final' => true,
                'sequence' => 2,
                'transitions_from' => [$firstSlug],
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $response->assertSuccessful();
        $data = $response->json('data.createOrderStatus');
        $this->assertEquals('Second Status', $data['name']);
        $this->assertEquals(2, $data['sequence']);
    }
}
