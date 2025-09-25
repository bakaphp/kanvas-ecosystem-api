<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderStatusTransitions;
use Kanvas\Souk\Orders\Models\OrderTransitionHistory;
use Kanvas\Souk\Orders\Models\OrderTypes;

class OrderStatusTest extends OrderBase
{
    protected $orderTypeName = 'reservation';

    public function setUp(): void
    {
        parent::setUp();
        $orderType = OrderTypes::firstOrCreate([
            'name' => $this->orderTypeName,
            'apps_id' => $this->apps->id,
        ]);

        $this->createStatuses($this->apps, $orderType, [
            [
                'slug' => 'draft',
                'name' => 'Draft',
                'is_default' => true,
                'is_final' => false,
                'transitions' => [],
            ],
            [
                'slug' => 'pending',
                'name' => 'Pending',
                'is_default' => false,
                'is_final' => false,
                'transitions' => ['draft'],
            ],
            [
                'slug' => 'paid',
                'name' => 'Paid',
                'is_default' => false,
                'is_final' => true,
                'transitions' => ['pending'],
            ],
            [
                'slug' => 'cancelled',
                'name' => 'Cancelled',
                'is_default' => false,
                'is_final' => true,
                'transitions' => ['pending', 'paid'],
            ],
        ]);
    }

    public function createStatuses(Apps $app, OrderTypes $orderType, array $statuses)
    {
        $savedStatuses = [];
        foreach ($statuses as $status) {
            $createdStatus = OrderStatus::firstOrCreate([
                'order_types_id' => $orderType->id,
                'apps_id' => $app->id,
                'slug' => $status['slug'],
                'name' => $status['name'],
                'is_default' => $status['is_default'],
                'is_final' => $status['is_final'],
            ]);

            $savedStatuses[$status['slug']] = $createdStatus->id;
        }

        foreach ($statuses as $status) {
            foreach ($status['transitions'] as $transition) {
                OrderStatusTransitions::firstOrCreate([
                    'order_types_id' => $orderType->id,
                    'from_status_id' => $savedStatuses[$transition],
                    'to_status_id' => $savedStatuses[$status['slug']],
                    'name' => $status['name'],
                ]);
            }
        }
    }

    public function testOrderStatusDefault(): void
    {
        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'slots',
                'value' => 100
            ]
        ])->json()['data']['createProduct'];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
            ],
            attributes: [
                [
                    'name' => 'timezone',
                    'value' => 'America/New_York',
                ],
            ]
        )->json()['data']['createVariant'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
            ]
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $order = $this->createOrderFromCart(
            variantId: $variantResponse['id'],
            quantity: 1,
            metadata: [
                'data' => []
            ],
            orderType: $this->orderTypeName
        );

        $response = $this->graphQL('
            query Orders($order_id: Mixed!) {
                orders(first: 1, where: { column: ID, operator: EQ, value: $order_id }) {
                    data {
                        id
                        order_status {
                            id
                            name
                        }
                    }
                }
            }
        ', [
            'order_id' => $order->id,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $orderTransitionHistory = OrderTransitionHistory::where([
            'order_id' => $order->id,
            'from_status_id' => null,
            'to_status_id' => $order->orderStatus->id,
            'is_current' => true,
        ])->first();

        $this->assertNotNull($orderTransitionHistory);

        $this->assertEquals($response->json('data.orders.data.0.order_status.name'), 'Draft');
    }

    public function testOrderStatusTransition(): void
    {
        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'slots',
                'value' => 100
            ]
        ])->json()['data']['createProduct'];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
            ],
            attributes: [
                [
                    'name' => 'timezone',
                    'value' => 'America/New_York',
                ],
            ]
        )->json()['data']['createVariant'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
            ]
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $order = $this->createOrderFromCart(
            variantId: $variantResponse['id'],
            quantity: 1,
            metadata: [
                'data' => []
            ],
            currency: 'DOP',
            orderType: $this->orderTypeName
        );

        $badUpdate = $this->graphQL('
            mutation transitionOrderStatus($input: TransitionOrderStatusInput!) {
                transitionOrderStatus(input: $input) {
                    message
                }
            }
        ', [
            'input' => [
                'order_id' => $order->id,
                'status_slug' => 'cancelled',
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertEquals($badUpdate->json('errors.0.message'), 'The status Cancelled is not a valid transition from Draft');

        $goodUpdate = $this->graphQL('
            mutation transitionOrderStatus($input: TransitionOrderStatusInput!) {
                transitionOrderStatus(input: $input) {
                    message
                }
            }
        ', [
            'input' => [
                'order_id' => $order->id,
                'status_slug' => 'pending',
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $orderTransitionHistory = OrderTransitionHistory::where([
            'order_id' => $order->id,
            'description' => 'Order status changed from draft to pending',
        ])->first();

        $this->assertEquals($goodUpdate->json('data.transitionOrderStatus.message'), 'Order status transitioned successfully');
        $this->assertNotNull($orderTransitionHistory);
        $this->assertEquals(trim($order->currency), 'DOP');
    }

    public function testOrderStatusTransitionWithCustomDate(): void
    {
        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'slots',
                'value' => 100
            ]
        ])->json()['data']['createProduct'];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
            ],
            attributes: [
                [
                    'name' => 'timezone',
                    'value' => 'America/New_York',
                ],
            ]
        )->json()['data']['createVariant'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
            ]
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $order = $this->createOrderFromCart(
            variantId: $variantResponse['id'],
            quantity: 1,
            metadata: [
                'data' => []
            ],
            orderType: $this->orderTypeName
        );

        $customDate = '2024-01-15 14:30:00';

        $transitionWithDate = $this->graphQL('
            mutation transitionOrderStatus($input: TransitionOrderStatusInput!) {
                transitionOrderStatus(input: $input) {
                    message
                }
            }
        ', [
            'input' => [
                'order_id' => $order->id,
                'status_slug' => 'pending',
                'date' => $customDate,
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $orderTransitionHistory = OrderTransitionHistory::where([
            'order_id' => $order->id,
            'description' => 'Order status changed from draft to pending',
        ])->first();

        $this->assertEquals($transitionWithDate->json('data.transitionOrderStatus.message'), 'Order status transitioned successfully');
        $this->assertNotNull($orderTransitionHistory);
        $this->assertEquals($orderTransitionHistory->changed_at->format('Y-m-d H:i:s'), $customDate);
    }

    public function testOrderTransitionHistoryMethods(): void
    {
        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'slots',
                'value' => 100
            ]
        ])->json()['data']['createProduct'];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
            ],
            attributes: [
                [
                    'name' => 'timezone',
                    'value' => 'America/New_York',
                ],
            ]
        )->json()['data']['createVariant'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
            ]
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        // Create order (starts in 'draft' status)
        $order = $this->createOrderFromCart(
            variantId: $variantResponse['id'],
            quantity: 1,
            metadata: [
                'data' => []
            ],
            orderType: $this->orderTypeName
        );

        // Refresh order to get relationships
        $order->refresh();

        // Test getFirstTransition() - should be the initial draft status
        $firstTransition = $order->getFirstTransition();
        $this->assertNotNull($firstTransition, 'First transition should exist');
        $this->assertNull($firstTransition->from_status_id, 'First transition should have null from_status_id');
        $this->assertEquals('draft', $firstTransition->toStatus->slug, 'First transition should be to draft status');

        // Test getCurrentTransition() - should be the current active transition
        $currentTransition = $order->getCurrentTransition();
        $this->assertNotNull($currentTransition, 'Current transition should exist');
        $this->assertTrue($currentTransition->is_current, 'Current transition should have is_current = true');
        $this->assertEquals('draft', $currentTransition->toStatus->slug, 'Current transition should be to draft status');

        // Test getLastTransition() - should be same as first at this point
        $lastTransition = $order->getLastTransition();
        $this->assertNotNull($lastTransition, 'Last transition should exist');
        $this->assertEquals($firstTransition->id, $lastTransition->id, 'Last and first transition should be the same initially');

        // Test getTransitionByStatus() - find draft transition
        $draftTransition = $order->getTransitionByStatus('draft');
        $this->assertNotNull($draftTransition, 'Draft transition should be found');
        $this->assertEquals('draft', $draftTransition->toStatus->slug, 'Found transition should be to draft status');

        // Test non-existent status
        $nonExistentTransition = $order->getTransitionByStatus('non-existent');
        $this->assertNull($nonExistentTransition, 'Non-existent status transition should return null');

        // Transition to 'pending' status
        $response = $this->graphQL('
            mutation transitionOrderStatus($input: TransitionOrderStatusInput!) {
                transitionOrderStatus(input: $input) {
                    message
                }
            }
        ', [
            'input' => [
                'order_id' => $order->id,
                'status_slug' => 'pending',
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        // Refresh order to get updated relationships
        $order->refresh();
        print_r($order->orderTransitionHistory()->get());

        // Now test with multiple transitions
        $newLastTransition = $order->getLastTransition();
        $this->assertNotNull($newLastTransition, 'New last transition should exist');
        $this->assertEquals('pending', $newLastTransition->toStatus->slug, 'Last transition should now be to pending status');
        $this->assertNotEquals($firstTransition->id, $newLastTransition->id, 'Last transition should be different from first after status change');

        // Test getCurrentTransition() after status change
        $newCurrentTransition = $order->getCurrentTransition();
        $this->assertNotNull($newCurrentTransition, 'New current transition should exist');
        $this->assertTrue($newCurrentTransition->is_current, 'New current transition should have is_current = true');
        $this->assertEquals('pending', $newCurrentTransition->toStatus->slug, 'Current transition should now be to pending status');

        // Test getTransitionByStatus() for both statuses
        $pendingTransition = $order->getTransitionByStatus('pending');
        $this->assertNotNull($pendingTransition, 'Pending transition should be found');
        $this->assertEquals('pending', $pendingTransition->toStatus->slug, 'Found transition should be to pending status');

        $stillDraftTransition = $order->getTransitionByStatus('draft');
        $this->assertNotNull($stillDraftTransition, 'Draft transition should still be found');
        $this->assertEquals('draft', $stillDraftTransition->toStatus->slug, 'Draft transition should still exist');

        // Transition to 'paid' status
        $this->graphQL('
            mutation transitionOrderStatus($input: TransitionOrderStatusInput!) {
                transitionOrderStatus(input: $input) {
                    message
                }
            }
        ', [
            'input' => [
                'order_id' => $order->id,
                'status_slug' => 'paid',
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        // Refresh order one more time
        $order->refresh();

        // Final tests with three transitions
        $finalLastTransition = $order->getLastTransition();
        $this->assertNotNull($finalLastTransition, 'Final last transition should exist');
        $this->assertEquals('paid', $finalLastTransition->toStatus->slug, 'Final last transition should be to paid status');

        $finalFirstTransition = $order->getFirstTransition();
        $this->assertEquals('draft', $finalFirstTransition->toStatus->slug, 'First transition should still be to draft status');
        $this->assertNotEquals($finalFirstTransition->id, $finalLastTransition->id, 'First and last transitions should be different');

        // Test all three status transitions can be found
        $this->assertNotNull($order->getTransitionByStatus('draft'), 'Draft transition should be found');
        $this->assertNotNull($order->getTransitionByStatus('pending'), 'Pending transition should be found');
        $this->assertNotNull($order->getTransitionByStatus('paid'), 'Paid transition should be found');

        // Current transition should be 'paid'
        $finalCurrentTransition = $order->getCurrentTransition();
        $this->assertEquals('paid', $finalCurrentTransition->toStatus->slug, 'Final current transition should be to paid status');
    }
}
