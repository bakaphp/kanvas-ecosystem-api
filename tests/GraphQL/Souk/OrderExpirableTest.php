<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Internal\Activities\RecalculateSlotCapacityActivity;
use Kanvas\Connectors\Movipass\Actions\CheckExpiringOrders;
use Kanvas\Connectors\Movipass\Notifications\ExpiringReservationPushNotification;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class OrderExpirableTest extends TestCase
{
    use InventoryCases;

    protected Variants $variant;
    protected Regions $region;
    protected Companies $company;
    protected Users $user;
    protected Apps $apps;
    protected array $warehouseResponse;
    protected array $channelResponse;

    public function setUp(): void
    {
        parent::setUp();
        $this->apps = app(Apps::class);
        $this->user = Auth::user();
        $this->company = $this->user->getCurrentCompany();
        $this->region = Regions::getDefault($this->company, $this->apps);

        $this->warehouseResponse = $this->createWarehouses((string) $this->region->getId())->json()['data']['createWarehouse'];
        $this->channelResponse = $this->createChannel()->json()['data']['createChannel'];
    }

    public function createDraftOrder(
        array $metadata,
        string|int $variantId,
        int $quantity = 1,
    ): Order {
        $data = [
            'email' => fake()->email(),
            'region_id' => $this->region->getId(),
            'metadata' => $metadata,
            'customer' => [
                'firstname' => fake()->firstName(),
                'lastname' => fake()->lastName(),
            ],
            'shipping_address' => [
                'address' => fake()->address(),
                'address_2' => fake()->postcode(),
                'city' => fake()->city(),
                'state' => fake()->state(),
            ],
            'items' => [
                [
                    'variant_id' => $variantId,
                    'quantity' => $quantity,
                ],
            ],
        ];

        // Perform GraphQL mutation to create a draft order
        $response = $this->graphQL('
            mutation createDraftOrder($input: DraftOrderInput!) {
                createDraftOrder(input: $input) {
                    id
                }
            }
        ', [
            'input' => $data,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $order = Order::find($response->json('data.createDraftOrder.id'));

        return $order;
    }

    public function testOrderExpirable(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::CHECK_EXPIRED_ORDERS->value, '1');
        $regionResponse = $this->createRegion()->json()['data']['createRegion'];
        $warehouseResponse = $this->createWarehouses($regionResponse['id'])->json()['data']['createWarehouse'];
        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'slots',
                'value' => 100,
            ],
        ])->json()['data']['createProduct'];
        $region = Regions::find($regionResponse['id']);
        $company = $region->company;

        $warehouseData = [
            'id' => $warehouseResponse['id'],
        ];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: $warehouseData,
            attributes: [
                [
                    'name' => 'timezone',
                    'value' => 'America/New_York',
                ],
            ]
        )->json()['data']['createVariant'];

        $channelResponse = $this->createChannel()->json()['data']['createChannel'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $channelResponse['id'],
            warehouseData: $warehouseData
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $warehouseResponse['id'],
            amount: 100
        );

        $variant = Variants::find($variantResponse['id']);
        $channel = $variant->variantChannels()->where('channels_id', $channelResponse['id'])->first();
        $variantWarehouse = $channel?->productVariantWarehouse()->first();

        $data = [
            'email' => fake()->email(),
            'region_id' => $region->getId(),
            'metadata' => [
                'data' => [
                    'start_at' => now()->addMinutes(30)->toDateTimeString(),
                    'end_at' => now()->addHours(2)->toDateTimeString(),
                ],
            ],
            'customer' => [
                'firstname' => fake()->firstName(),
                'lastname' => fake()->lastName(),
            ],
            'shipping_address' => [
                'address' => fake()->address(),
                'address_2' => fake()->postcode(),
                'city' => fake()->city(),
                'state' => fake()->state(),
            ],
            'items' => [
                [
                    'variant_id' => $variantResponse['id'],
                    'quantity' => 1,
                ],
            ],
        ];

        // Perform GraphQL mutation to create a draft order
        $response = $this->graphQL('
            mutation createDraftOrder($input: DraftOrderInput!) {
                createDraftOrder(input: $input) {
                    id
                }
            }
        ', [
            'input' => $data,
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
            'X-Kanvas-App' => $app->key,
        ]);

        $order = $response->json()['data']['createDraftOrder'];
        $order = Order::fromApp($app)->find($order['id']);
        // addItem() doesn't set is_public, but items() filters by is_public=1
        $order->allItems()->update(['is_public' => 1]);
        // lets simulate the variant warehouse quantity decrease
        $activity = new RecalculateSlotCapacityActivity(0, now()->toDateTimeString(), StoredWorkflow::make(), []);
        $activity->execute($order, $app, []);
        // variant quantity should decrease (order is active, end_at is in the future)
        $this->assertEquals(99, $variantWarehouse->refresh()->quantity);

        // simulate expiry by setting end_at far enough in the past to account for timezone parsing
        $order->metadata = array_merge($order->metadata ?? [], [
            'data' => [
                'start_at' => now()->subHours(8)->toDateTimeString(),
                'end_at' => now()->subHours(6)->toDateTimeString(),
            ],
        ]);
        $order->saveOrFail();

        // finish expired order
        Artisan::call('kanvas-souk:order-finish-expired', ['app_id' => $app->getId()]);
        $this->assertEquals(100, $variantWarehouse->refresh()->quantity);
    }

    public function testOrderExpirableLegacy(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::CHECK_EXPIRED_ORDERS->value, '1');
        $regionResponse = $this->createRegion()->json()['data']['createRegion'];
        $warehouseResponse = $this->createWarehouses($regionResponse['id'])->json()['data']['createWarehouse'];
        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'capacity',
                'value' => [
                    'occupiedParkingSpaces' => 50,
                ],
            ],
        ])->json()['data']['createProduct'];
        $region = Regions::find($regionResponse['id']);
        $company = $region->company;

        $warehouseData = [
            'id' => $warehouseResponse['id'],
        ];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: $warehouseData,
            attributes: [
                [
                    'name' => 'timezone',
                    'value' => 'America/New_York',
                ],
            ]
        )->json()['data']['createVariant'];

        $channelResponse = $this->createChannel()->json()['data']['createChannel'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $channelResponse['id'],
            warehouseData: $warehouseData
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $warehouseResponse['id'],
            amount: 100
        );

        $variant = Variants::find($variantResponse['id']);
        $channel = $variant->variantChannels()->where('channels_id', $channelResponse['id'])->first();
        $variantWarehouse = $channel?->productVariantWarehouse()->first();

        $data = [
            'email' => fake()->email(),
            'region_id' => $region->getId(),
            'metadata' => [
                'data' => [
                    'start_at' => now()->addMinutes(30)->toDateTimeString(),
                    'end_at' => now()->addHours(2)->toDateTimeString(),
                ],
            ],
            'customer' => [
                'firstname' => fake()->firstName(),
                'lastname' => fake()->lastName(),
            ],
            'shipping_address' => [
                'address' => fake()->address(),
                'address_2' => fake()->postcode(),
                'city' => fake()->city(),
                'state' => fake()->state(),
            ],
            'items' => [
                [
                    'variant_id' => $variantResponse['id'],
                    'quantity' => 1,
                ],
            ],
        ];

        // Perform GraphQL mutation to create a draft order
        $response = $this->graphQL('
            mutation createDraftOrder($input: DraftOrderInput!) {
                createDraftOrder(input: $input) {
                    id
                }
            }
        ', [
            'input' => $data,
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
            'X-Kanvas-App' => $app->key,
        ]);

        $order = $response->json()['data']['createDraftOrder'];
        $order = Order::fromApp($app)->find($order['id']);
        // addItem() doesn't set is_public, but items() filters by is_public=1
        $order->allItems()->update(['is_public' => 1]);
        // lets simulate the variant warehouse quantity decrease
        $activity = new RecalculateSlotCapacityActivity(0, now()->toDateTimeString(), StoredWorkflow::make(), []);
        $activity->execute($order, $app, []);
        $variantProduct = $variant->product;
        // variant quantity should decrease (order is active, end_at is in the future)
        $this->assertEquals(49, $variantWarehouse->refresh()->quantity);
        $this->assertEquals(49, $variantProduct->refresh()->getAttributeByName('capacity')->value['availableParkingSpaces']);

        // simulate expiry by setting end_at far enough in the past to account for timezone parsing
        $order->metadata = array_merge($order->metadata ?? [], [
            'data' => [
                'start_at' => now()->subHours(8)->toDateTimeString(),
                'end_at' => now()->subHours(6)->toDateTimeString(),
            ],
        ]);
        $order->saveOrFail();

        // finish expired order
        Artisan::call('kanvas-souk:order-finish-expired', ['app_id' => $app->getId()]);
        $this->assertEquals(50, $variantWarehouse->refresh()->quantity);
        $this->assertEquals(50, $variantProduct->refresh()->getAttributeByName('capacity')->value['availableParkingSpaces']);
    }

    public function testOrderExpirableNotifications(): void
    {
        Notification::fake();
        $this->apps->set(ConfigurationEnum::CHECK_EXPIRED_ORDERS->value, '1');
        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'slots',
                'value' => 100,
            ],
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

        $timezone = 'America/New_York';
        Date::setTestNow(now()->startOfSecond());
        $rightNow = now($timezone)->toDateTimeString();
        $rightNowPlus15 = now($timezone)->addMinutes(15)->toDateTimeString();
        $rightNowPlus30 = now($timezone)->addMinutes(30)->toDateTimeString();
        $rightNowPlus5 = now($timezone)->addMinutes(5)->toDateTimeString();

        $reservation1 = $this->createDraftOrder(
            variantId: $variantResponse['id'],
            quantity: 1,
            metadata: [
                'data' => [
                    'start_at' => $rightNow,
                    'end_at' => $rightNowPlus15,
                    'notify_in' => 15,
                ],
            ],
        );

        $reservation2 = $this->createDraftOrder(
            variantId: $variantResponse['id'],
            quantity: 1,
            metadata: [
                'data' => [
                    'start_at' => $rightNow,
                    'end_at' => $rightNowPlus15,
                    'notify_in' => 15,
                ],
            ],
        );

        $reservation3 = $this->createDraftOrder(
            variantId: $variantResponse['id'],
            quantity: 1,
            metadata: [
                'data' => [
                    'start_at' => $rightNow,
                    'end_at' => $rightNowPlus5,
                    'notify_in' => 5,
                ],
            ],
        );

        $reservation4 = $this->createDraftOrder(
            variantId: $variantResponse['id'],
            quantity: 1,
            metadata: [
                'data' => [
                    'start_at' => $rightNow,
                    'end_at' => $rightNowPlus30,
                    'notify_in' => 30,
                ],
            ],
        );

        $checkExpiringOrders = new CheckExpiringOrders($this->apps);
        $orders = $checkExpiringOrders->execute($rightNow, [
            15,
            5,
        ], [$reservation1->getId(), $reservation2->getId(), $reservation3->getId(), $reservation4->getId()]);

        $this->assertEquals(3, $orders->count());

        $orders->each(function ($order) {
            $this->assertEquals($order->ends_in, $order->metadata['data']['notify_in']);
        });

        $checkExpiringOrders->notify($orders);
        Notification::assertSentTimes(ExpiringReservationPushNotification::class, 3);
        Artisan::call('kanvas:movipass-check-expiring-orders', ['app_id' => $this->apps->getId()]);
        Date::setTestNow();
    }

    public function testDuplicateMetadataValidation(): void
    {
        $app = app(Apps::class);

        $app->set(ConfigurationEnum::VALIDATE_METADATA_DUPLICATED_ENABLED->value, '1');

        $orderType = OrderTypes::firstOrCreate(
            ['name' => 'test-duplicate-validation', 'apps_id' => $app->getId(), 'companies_id' => 0],
        );
        $orderType->config = ['validate_metadata_duplicated_field' => 'data.tracking_id'];
        $orderType->saveQuietly();

        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'slots',
                'value' => 100,
            ],
        ])->json()['data']['createProduct'];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
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

        $uniqueTrackingId = 'TRACK-' . strtoupper(uniqid());

        // Create first order with UPPERCASE tracking ID
        $firstOrderData = [
            'cartId' => 0,
            'order_type' => $orderType->name,
            'customer' => [
                'email' => fake()->email(),
            ],
            'items' => [
                [
                    'variant_id' => $variantResponse['id'],
                    'quantity' => 1,
                ],
            ],
            'metadata' => [
                'data' => [
                    'tracking_id' => $uniqueTrackingId, // UPPERCASE
                    'start_at' => now()->addHour()->toDateTimeString(),
                    'end_at' => now()->addHours(2)->toDateTimeString(),
                ],
            ],
        ];

        $firstResponse = $this->graphQL('
            mutation createOrderFromCart($input: OrderCartInput!) {
                createOrderFromCart(input: $input) {
                    order {
                        id
                    }
                    message
                }
            }
        ', [
            'input' => $firstOrderData,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $app->key,
        ]);

        $this->assertNull($firstResponse->json('errors'));

        // Try to create second order with lowercase tracking ID (should fail - case-insensitive duplicate)
        $duplicateOrderData = [
            'cartId' => 0,
            'order_type' => $orderType->name,
            'customer' => [
                'email' => fake()->email(),
            ],
            'items' => [
                [
                    'variant_id' => $variantResponse['id'],
                    'quantity' => 1,
                ],
            ],
            'metadata' => [
                'data' => [
                    'tracking_id' => strtolower($uniqueTrackingId), // lowercase version - should be detected as duplicate
                    'start_at' => now()->addHour()->toDateTimeString(),
                    'end_at' => now()->addHours(2)->toDateTimeString(),
                ],
            ],
        ];

        $response = $this->graphQL('
            mutation createOrderFromCart($input: OrderCartInput!) {
                createOrderFromCart(input: $input) {
                    order {
                        id
                    }
                    message
                }
            }
        ', [
            'input' => $duplicateOrderData,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $app->key,
        ]);

        // Should return validation error
        $this->assertArrayHasKey('errors', $response->json());
        $this->assertStringContainsString("The metadata contains a duplicate value for field 'data.tracking_id'", $response->json('errors.0.message'));
    }

    public function testDuplicateMetadataValidationWithStatus(): void
    {
        $app = app(Apps::class);

        $app->set(ConfigurationEnum::VALIDATE_METADATA_DUPLICATED_ENABLED->value, '1');

        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'slots',
                'value' => 100,
            ],
        ])->json()['data']['createProduct'];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
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

        // Configure per-OrderType exclude statuses
        $salesOrderType = OrderTypes::where('name', 'sales')
            ->where('apps_id', $app->getId())
            ->first();

        if ($salesOrderType) {
            $salesOrderType->config = array_merge($salesOrderType->config ?? [], [
                'validate_metadata_duplicated_field' => 'data.tracking_id',
                'validate_metadata_duplicated_blocking_statuses' => 'active',
            ]);
            $salesOrderType->saveQuietly();
        }

        // Create first order with tracking ID
        $uniqueTrackingId = 'TEST-CANCELLED-' . fake()->uuid();
        $firstOrderData = [
            'cartId' => 0,
            'customer' => [
                'email' => fake()->email(),
            ],
            'order_type' => 'sales',
            'items' => [
                [
                    'variant_id' => $variantResponse['id'],
                    'quantity' => 1,
                ],
            ],
            'metadata' => [
                'data' => [
                    'tracking_id' => $uniqueTrackingId,
                    'start_at' => now()->addHour()->toDateTimeString(),
                    'end_at' => now()->addHours(2)->toDateTimeString(),
                ],
            ],
        ];

        $firstResponse = $this->graphQL('
            mutation createOrderFromCart($input: OrderCartInput!) {
                createOrderFromCart(input: $input) {
                    order {
                        id
                    }
                    message
                }
            }
        ', [
            'input' => $firstOrderData,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $app->key,
        ]);

        $this->assertNull($firstResponse->json('errors'));
        $orderId = $firstResponse->json('data.createOrderFromCart.order.id');

        // Change first order status to 'cancelled'
        $order = Order::findOrFail($orderId);
        $cancelledStatus = $order->orderType->statuses()->where('slug', 'cancelled')->first();

        if (! $cancelledStatus) {
            $cancelledStatus = $order->orderType->statuses()->create([
                'name' => 'Cancelled',
                'slug' => 'cancelled',
                'apps_id' => $app->getId(),
                'is_deleted' => 0,
            ]);
        }

        $order->order_status_id = $cancelledStatus->id;
        $order->save();

        // Try to create second order with same tracking ID (lowercase) - should SUCCEED because first order is cancelled
        $duplicateOrderData = [
            'cartId' => 0,
            'order_type' => 'sales',
            'customer' => [
                'email' => fake()->email(),
            ],
            'items' => [
                [
                    'variant_id' => $variantResponse['id'],
                    'quantity' => 1,
                ],
            ],
            'metadata' => [
                'data' => [
                    'tracking_id' => strtolower($uniqueTrackingId), // lowercase - but should be allowed because first order is cancelled
                    'start_at' => now()->addHour()->toDateTimeString(),
                    'end_at' => now()->addHours(2)->toDateTimeString(),
                ],
            ],
        ];

        $response = $this->graphQL('
            mutation createOrderFromCart($input: OrderCartInput!) {
                createOrderFromCart(input: $input) {
                    order {
                        id
                    }
                    message
                }
            }
        ', [
            'input' => $duplicateOrderData,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $app->key,
        ]);

        // Should NOT return validation error - cancelled orders are excluded
        $this->assertNull($response->json('errors'));
        $this->assertNotNull($response->json('data.createOrderFromCart.order.id'));
    }

    public function testDuplicateMetadataValidationBlockedByActiveOrderStatus(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::VALIDATE_METADATA_DUPLICATED_ENABLED->value, '1');

        $productResponse = $this->createProduct(attributes: [
            ['name' => 'slots', 'value' => 100],
        ])->json()['data']['createProduct'];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']]
        )->json()['data']['createVariant'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']]
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $orderType = OrderTypes::withoutSyncingToSearch(
            fn () => OrderTypes::firstOrCreate(
                ['name' => 'parking', 'apps_id' => $app->getId(), 'companies_id' => 0],
            )
        );
        $orderType->config = array_merge($orderType->config ?? [], [
            'validate_metadata_duplicated_field' => 'data.vehiclePlate',
            'validate_metadata_duplicated_blocking_statuses' => 'active',
        ]);
        $orderType->saveQuietly();

        $vehiclePlate = 'T' . strtoupper(uniqid());

        $firstResponse = $this->graphQL('
            mutation createOrderFromCart($input: OrderCartInput!) {
                createOrderFromCart(input: $input) {
                    order { id }
                    message
                }
            }
        ', [
            'input' => [
                'cartId' => 0,
                'order_type' => $orderType->name,
                'customer' => ['email' => fake()->email()],
                'items' => [['variant_id' => $variantResponse['id'], 'quantity' => 1]],
                'metadata' => ['data' => ['vehiclePlate' => $vehiclePlate]],
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $app->key,
        ]);

        $this->assertNull($firstResponse->json('errors'));
        $orderId = $firstResponse->json('data.createOrderFromCart.order.id');

        // Set first order to 'active' orderStatus
        $order = Order::findOrFail($orderId);
        $activeStatus = $order->orderType->statuses()->where('slug', 'active')->first();
        if (! $activeStatus) {
            $activeStatus = $order->orderType->statuses()->create([
                'name' => 'Active',
                'slug' => 'active',
                'apps_id' => $app->getId(),
                'is_deleted' => 0,
            ]);
        }
        $order->order_status_id = $activeStatus->id;
        $order->save();

        // Same plate (case-insensitive) must be blocked while first order is active
        $response = $this->graphQL('
            mutation createOrderFromCart($input: OrderCartInput!) {
                createOrderFromCart(input: $input) {
                    order { id }
                    message
                }
            }
        ', [
            'input' => [
                'cartId' => 0,
                'order_type' => $orderType->name,
                'customer' => ['email' => fake()->email()],
                'items' => [['variant_id' => $variantResponse['id'], 'quantity' => 1]],
                'metadata' => ['data' => ['vehiclePlate' => strtolower($vehiclePlate)]],
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $app->key,
        ]);

        $this->assertArrayHasKey('errors', $response->json());
        $this->assertStringContainsString(
            "duplicate value for field 'data.vehiclePlate'",
            $response->json('errors.0.message')
        );
    }

    private function buildMovipassVariantWithCapacity(int $maxCapacity): Variants
    {
        $productResponse = $this->createProduct(attributes: [
            ['name' => 'slots', 'value' => $maxCapacity],
        ])->json()['data']['createProduct'];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
        )->json()['data']['createVariant'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: $maxCapacity,
        );

        $variant = Variants::find($variantResponse['id']);
        $variantWarehouse = $variant->variantWarehouses()->first();
        $variantWarehouse->max_capacity = $maxCapacity;
        $variantWarehouse->save();

        return $variant->fresh();
    }

    private function createMovipassOrder(Variants $variant, array $endAtMetadata): Order
    {
        $orderType = OrderTypes::withoutSyncingToSearch(fn () => OrderTypes::firstOrCreate(
            [
                'name' => 'movipass',
                'apps_id' => $this->apps->id,
                'companies_id' => $this->company->id,
            ]
        ));

        if (! $orderType->isExpirable()) {
            $orderType->config = ['expirable' => true];
            $orderType->save();
        }

        return Order::withoutSyncingToSearch(function () use ($variant, $orderType, $endAtMetadata) {
            $order = Order::create([
                'apps_id' => $this->apps->id,
                'companies_id' => $this->company->id,
                'order_types_id' => $orderType->id,
                'region_id' => $this->region->id,
                'users_id' => $this->user->id,
                'people_id' => 0,
                'status' => 'draft',
                'fulfillment_status' => 'pending',
                'metadata' => $endAtMetadata,
                'is_deleted' => 0,
            ]);

            $order->allItems()->create([
                'apps_id' => $this->apps->id,
                'product_name' => $variant->product->name,
                'product_sku' => $variant->sku,
                'quantity' => 1,
                'unit_price_net_amount' => 0,
                'unit_price_gross_amount' => 0,
                'is_shipping_required' => false,
                'quantity_fulfilled' => 0,
                'variant_id' => $variant->getId(),
                'variant_name' => $variant->name,
                'tax_rate' => 0,
                'currency' => 'USD',
                'is_public' => 1,
                'is_deleted' => 0,
            ]);

            return $order;
        });
    }

    public function testOrderExpirableMovipass(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::CHECK_EXPIRED_ORDERS->value, '1');

        // max_capacity = 3; will have 2 active + 1 expired
        $variant = $this->buildMovipassVariantWithCapacity(3);
        $variantWarehouse = $variant->variantWarehouses()->first();

        // Use subHours(6) so the expired time is past even when parsed under any UTC offset timezone
        $activeEndAt = ['data' => ['end_at' => now()->addHours(2)->toDateTimeString()]];
        $expiredEndAt = ['data' => ['end_at' => now()->subHours(6)->toDateTimeString()]];

        $activeOrder1 = $this->createMovipassOrder($variant, $activeEndAt);
        $activeOrder2 = $this->createMovipassOrder($variant, $activeEndAt);
        $expiredOrder = $this->createMovipassOrder($variant, $expiredEndAt);

        // Simulate warehouse quantity tracking: 3 orders exist → available = max(3) - active(3) = 0
        $activity = new RecalculateSlotCapacityActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );
        $activity->execute($activeOrder1, $app, []);
        // Activity now uses GetSlotAvailabilityAction (excludes expired orders):
        // 3 orders exist but only 2 have end_at > now → available = max(3) - active(2) = 1
        $this->assertEquals(1, $variantWarehouse->refresh()->quantity);

        Artisan::call('kanvas-souk:order-finish-expired', ['app_id' => $app->getId()]);

        // Expired order must be fulfilled
        $this->assertEquals('fulfilled', $expiredOrder->fresh()->fulfillment_status);

        // Active orders must remain untouched
        $this->assertNotEquals('fulfilled', $activeOrder1->fresh()->fulfillment_status);
        $this->assertNotEquals('fulfilled', $activeOrder2->fresh()->fulfillment_status);

        // After command: expired order is fulfilled → active count stays at 2 → available = 3-2 = 1
        $this->assertEquals(1, $variantWarehouse->refresh()->quantity);
    }

    public function testOrderExpirableMovipassMultiVariant(): void
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::CHECK_EXPIRED_ORDERS->value, '1');

        // Two variants on the same product: Floor1 max=3, Floor2 max=2
        $floor1Variant = $this->buildMovipassVariantWithCapacity(3);
        $product = $floor1Variant->product;

        // Build Floor2 variant on the same product
        $floor2VariantResponse = $this->createVariant(
            productId: (string) $product->getId(),
            warehouseData: ['id' => $this->warehouseResponse['id']],
        )->json()['data']['createVariant'];

        $this->addVariantToChannel(
            variantId: $floor2VariantResponse['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
        );

        $this->addVariantToWarehouse(
            variantId: $floor2VariantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: 2,
        );

        $floor2Variant = Variants::find($floor2VariantResponse['id']);
        $floor2WarehouseRecord = $floor2Variant->variantWarehouses()->first();
        $floor2WarehouseRecord->max_capacity = 2;
        $floor2WarehouseRecord->save();
        $floor2Variant = $floor2Variant->fresh();

        $floor1WarehouseRecord = $floor1Variant->variantWarehouses()->first();

        // Use subHours(6) so the expired time is past even when parsed under any UTC offset timezone
        $activeEndAt = ['data' => ['end_at' => now()->addHours(2)->toDateTimeString()]];
        $expiredEndAt = ['data' => ['end_at' => now()->subHours(6)->toDateTimeString()]];

        // Floor1: 1 active + 1 expired
        $floor1ActiveOrder = $this->createMovipassOrder($floor1Variant, $activeEndAt);
        $floor1ExpiredOrder = $this->createMovipassOrder($floor1Variant, $expiredEndAt);

        // Floor2: 1 active only
        $floor2ActiveOrder = $this->createMovipassOrder($floor2Variant, $activeEndAt);

        // Simulate warehouse quantity tracking for Floor1: 2 orders → available = max(3) - active(2) = 1
        $activity = new RecalculateSlotCapacityActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );
        $activity->execute($floor1ActiveOrder, $app, []);
        // Activity now uses GetSlotAvailabilityAction (excludes expired orders):
        // 2 floor1 orders exist but only 1 has end_at > now → available = max(3) - active(1) = 2
        $this->assertEquals(2, $floor1WarehouseRecord->refresh()->quantity);

        Artisan::call('kanvas-souk:order-finish-expired', ['app_id' => $app->getId()]);

        // Only the Floor1 expired order should be fulfilled
        $this->assertEquals('fulfilled', $floor1ExpiredOrder->fresh()->fulfillment_status);
        $this->assertNotEquals('fulfilled', $floor1ActiveOrder->fresh()->fulfillment_status);
        $this->assertNotEquals('fulfilled', $floor2ActiveOrder->fresh()->fulfillment_status);

        // After command: expired floor1 order is fulfilled → active count = 1 → available = 3-1 = 2
        $this->assertEquals(2, $floor1WarehouseRecord->refresh()->quantity);
    }
}
