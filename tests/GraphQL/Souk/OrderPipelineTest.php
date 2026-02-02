<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderStatusTransitions;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Orders\Repositories\OrderRepository;

class OrderPipelineTest extends OrderBase
{
    protected $orderTypeName = 'pipeline-test';

    public function setUp(): void
    {
        parent::setUp();
        $orderType = OrderTypes::firstOrCreate([
            'name' => $this->orderTypeName,
            'apps_id' => $this->apps->id,
        ]);

        $this->createStatusesWithSequence($this->apps, $orderType, [
            [
                'slug' => 'received',
                'name' => 'Received',
                'is_default' => true,
                'is_final' => false,
                'sequence' => 1,
                'transitions' => [],
            ],
            [
                'slug' => 'paid',
                'name' => 'Paid',
                'is_default' => false,
                'is_final' => false,
                'sequence' => 2,
                'transitions' => ['received'],
            ],
            [
                'slug' => 'dispatched',
                'name' => 'Dispatched',
                'is_default' => false,
                'is_final' => true,
                'sequence' => 3,
                'transitions' => ['paid'],
            ],
            [
                'slug' => 'cancelled',
                'name' => 'Cancelled',
                'is_default' => false,
                'is_final' => true,
                'sequence' => 4,
                'transitions' => ['received', 'paid'],
            ],
        ]);
    }

    protected function createStatusesWithSequence(Apps $app, OrderTypes $orderType, array $statuses): void
    {
        $savedStatuses = [];
        foreach ($statuses as $status) {
            $createdStatus = OrderStatus::firstOrCreate([
                'order_types_id' => $orderType->id,
                'apps_id' => $app->id,
                'slug' => $status['slug'],
            ], [
                'name' => $status['name'],
                'is_default' => $status['is_default'],
                'is_final' => $status['is_final'],
                'sequence' => $status['sequence'],
            ]);

            // Ensure sequence is set even if record already existed
            $createdStatus->update(['sequence' => $status['sequence']]);
            $savedStatuses[$status['slug']] = $createdStatus->id;
        }

        foreach ($statuses as $status) {
            foreach ($status['transitions'] as $fromSlug) {
                OrderStatusTransitions::firstOrCreate([
                    'order_types_id' => $orderType->id,
                    'from_status_id' => $savedStatuses[$fromSlug],
                    'to_status_id' => $savedStatuses[$status['slug']],
                    'name' => $status['name'],
                ]);
            }
        }
    }

    public function testGetOrderPipelineAction(): void
    {
        $productResponse = $this->createProduct(attributes: [
            ['name' => 'slots', 'value' => 100],
        ])->json()['data']['createProduct'];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
            attributes: [['name' => 'timezone', 'value' => 'America/New_York']],
        )->json()['data']['createVariant'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: 100,
        );

        $order = $this->createOrderFromCart(
            variantId: $variantResponse['id'],
            quantity: 1,
            metadata: ['data' => []],
            orderType: $this->orderTypeName,
        );

        $pipeline = (new OrderRepository($order))->getPipeline();

        $this->assertCount(4, $pipeline['stages']);
        $this->assertNotNull($pipeline['current_status']);
        $this->assertEquals('received', $pipeline['current_status']->slug);

        // First stage should be current
        $this->assertTrue($pipeline['stages'][0]['is_current']);
        $this->assertFalse($pipeline['stages'][0]['is_completed']);

        // Subsequent stages should not be current or completed
        $this->assertFalse($pipeline['stages'][1]['is_current']);
        $this->assertFalse($pipeline['stages'][1]['is_completed']);

        // Verify sequence ordering
        $this->assertEquals(1, $pipeline['stages'][0]['sequence']);
        $this->assertEquals(2, $pipeline['stages'][1]['sequence']);
        $this->assertEquals(3, $pipeline['stages'][2]['sequence']);
        $this->assertEquals(4, $pipeline['stages'][3]['sequence']);
    }

    public function testGetNextOrderStatusAction(): void
    {
        $productResponse = $this->createProduct(attributes: [
            ['name' => 'slots', 'value' => 100],
        ])->json()['data']['createProduct'];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
            attributes: [['name' => 'timezone', 'value' => 'America/New_York']],
        )->json()['data']['createVariant'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: 100,
        );

        $order = $this->createOrderFromCart(
            variantId: $variantResponse['id'],
            quantity: 1,
            metadata: ['data' => []],
            orderType: $this->orderTypeName,
        );

        // From "received", next should be "paid" (sequence 2)
        $nextStatus = $order->orderType->nextStatus($order);
        $this->assertEquals('paid', $nextStatus->slug);
    }

    public function testGetNextOrderStatusFromFinalStateThrows(): void
    {
        $productResponse = $this->createProduct(attributes: [
            ['name' => 'slots', 'value' => 100],
        ])->json()['data']['createProduct'];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
            attributes: [['name' => 'timezone', 'value' => 'America/New_York']],
        )->json()['data']['createVariant'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: 100,
        );

        $order = $this->createOrderFromCart(
            variantId: $variantResponse['id'],
            quantity: 1,
            metadata: ['data' => []],
            orderType: $this->orderTypeName,
        );

        // Transition to paid, then dispatched (final)
        $this->graphQL('
            mutation transitionOrderStatus($input: TransitionOrderStatusInput!) {
                transitionOrderStatus(input: $input) { message }
            }
        ', ['input' => ['order_id' => $order->id, 'status_slug' => 'paid']], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->graphQL('
            mutation transitionOrderStatus($input: TransitionOrderStatusInput!) {
                transitionOrderStatus(input: $input) { message }
            }
        ', ['input' => ['order_id' => $order->id, 'status_slug' => 'dispatched']], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $order->refresh();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Order is already in final state');
        $order->orderType->nextStatus($order);
    }

    public function testOrderPipelineGraphQLQuery(): void
    {
        $productResponse = $this->createProduct(attributes: [
            ['name' => 'slots', 'value' => 100],
        ])->json()['data']['createProduct'];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
            attributes: [['name' => 'timezone', 'value' => 'America/New_York']],
        )->json()['data']['createVariant'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: 100,
        );

        $order = $this->createOrderFromCart(
            variantId: $variantResponse['id'],
            quantity: 1,
            metadata: ['data' => []],
            orderType: $this->orderTypeName,
        );

        $response = $this->graphQL('
            query orderPipeline($order_id: ID!) {
                orderPipeline(order_id: $order_id) {
                    stages {
                        status {
                            id
                            name
                            slug
                            sequence
                        }
                        is_current
                        is_completed
                        sequence
                    }
                    current_status {
                        id
                        name
                        slug
                    }
                    available_transitions {
                        id
                        name
                        slug
                    }
                }
            }
        ', [
            'order_id' => $order->id,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $data = $response->json('data.orderPipeline');
        $this->assertNotNull($data);
        $this->assertCount(4, $data['stages']);
        $this->assertEquals('received', $data['current_status']['slug']);

        // First stage is current
        $this->assertTrue($data['stages'][0]['is_current']);
        $this->assertFalse($data['stages'][0]['is_completed']);

        // available_transitions should include "paid" and "cancelled" (from received)
        $transitionSlugs = array_column($data['available_transitions'], 'slug');
        $this->assertContains('paid', $transitionSlugs);
    }

    public function testTransitionOrderStatusAutoAdvance(): void
    {
        $productResponse = $this->createProduct(attributes: [
            ['name' => 'slots', 'value' => 100],
        ])->json()['data']['createProduct'];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
            attributes: [['name' => 'timezone', 'value' => 'America/New_York']],
        )->json()['data']['createVariant'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: 100,
        );

        $order = $this->createOrderFromCart(
            variantId: $variantResponse['id'],
            quantity: 1,
            metadata: ['data' => []],
            orderType: $this->orderTypeName,
        );

        // Auto-advance without specifying status_slug
        $response = $this->graphQL('
            mutation transitionOrderStatus($input: TransitionOrderStatusInput!) {
                transitionOrderStatus(input: $input) {
                    status
                    message
                }
            }
        ', [
            'input' => [
                'order_id' => $order->id,
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertEquals('success', $response->json('data.transitionOrderStatus.status'));

        // Order should now be "paid" (next after "received")
        $order->refresh();
        $this->assertEquals('paid', $order->orderStatus->slug);

        // Auto-advance again: paid → dispatched
        $response2 = $this->graphQL('
            mutation transitionOrderStatus($input: TransitionOrderStatusInput!) {
                transitionOrderStatus(input: $input) {
                    status
                    message
                }
            }
        ', [
            'input' => [
                'order_id' => $order->id,
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertEquals('success', $response2->json('data.transitionOrderStatus.status'));

        $order->refresh();
        $this->assertEquals('dispatched', $order->orderStatus->slug);
    }
}
