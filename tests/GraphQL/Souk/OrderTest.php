<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Models\Order;
use Tests\Connectors\Traits\HasStripeConfiguration;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\GraphQL\Souk\Traits\PaymentCases;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use InventoryCases;
    use PaymentCases;
    use HasStripeConfiguration;

    public function testCreateDraftOrder()
    {
        $variantWarehouse = VariantsWarehouses::first();
        $region = $variantWarehouse->warehouse->region;
        $company = $region->company;
        $user = $company->user;

        // Prepare input data for the draft order
        $data = [
            'email' => fake()->email(),
            'region_id' => $region->getId(),
            'metadata' => hash('sha256', random_bytes(10)),
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
                    'variant_id' => $variantWarehouse->variant->getId(),
                    'quantity' => 1,
                ],
            ],
        ];

        // Perform GraphQL mutation to create a draft order
        $response = $this->graphQL('
            mutation createDraftOrder($input: DraftOrderInput!) {
                createDraftOrder(input: $input) {
                    id
                    channel {
                        id
                    }
                }
            }
        ', [
            'input' => $data,
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]);

        $response->assertSuccessful();
        $orderData = $response->json()['data']['createDraftOrder'];
        $this->assertNotNull($orderData['channel']);
        $this->assertNotNull($orderData['channel']['id']);
    }

    public function testReturnOrderFromCart()
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);
        $productData = new Product(
            app: $app,
            company: $company,
            user: $user,
            name: fake()->name(),
            sku: fake()->unique()->word(),
            warehouses: [[
                'quantity' => 10,
                'price' => 0.29,
            ],
            ]
        );
        $this->setupStripeConfiguration($app);
        $product = (new CreateProductAction($productData, $user))->execute();
        $variant = $product->variants()->first();
        $warehouse = Warehouses::fromApp($app)->fromCompany($company)->first();
        $channel = Channels::fromApp($app)->fromCompany($company)->first();
        $variant->updatePriceInWarehouse($warehouse, 100);
        $variant->updatePriceInChannel($channel, 100);
        $app->del(ConfigurationEnum::SEND_NEW_ORDER_NOTIFICATION->value);
        $app->del(ConfigurationEnum::SEND_NEW_ORDER_TO_OWNER_NOTIFICATION->value);
        //$company->associateUserApp($user, $app, 1);
        // Prepare input data for the order

        $company->createAppWallet($app, ['name' => 'default'])->deposit(1000, [
            'description' => 'Initial deposit for order testing',
            'slug' => 'initial-deposit',
        ]);
        $items = [
            [
                'variant_id' => $variant->getId(),
                'amount' => 1,
                'quantity' => 1,
            ],
        ];

        $paymentIntentId = $this->createTestPaymentIntent($items[0], $app)->id;
        unset($items[0]['amount']);

        $data = [
            'cartId' => 'default',

            'customer' => [
                'email' => $user->email,
                'phone' => $user->phone,
            ],
            'items' => $items,
            'shipping_address' => [
                'address' => fake()->address(),
                'address_2' => fake()->postcode(),
                'city' => fake()->city(),
                'state' => fake()->state(),
            ],
            'metadata' => [
                'user_company_id' => $company->getId(),
                'paymentIntent' => [
                    'client_secret' => $paymentIntentId,
                ],
            ],
        ];

        // Perform GraphQL mutation to create a draft order
        $response = $this->graphQL('
            mutation createOrderFromCart($input: OrderCartInput!) {
                createOrderFromCart(input: $input) {
                    order {
                        id
                        channel {
                            id
                        }
                    }
                }
            }
        ', [
            'input' => $data,
        ]);

        $response->assertJson([
              'data' => [
                  'createOrderFromCart' => [
                      'order' => [
                          'id' => true, // Check if the order ID is returned
                      ],
                  ],
              ],
          ]);
    }

    public function testUpdateOrder()
    {
        $app = app(Apps::class);
        $regionResponse = $this->createRegion()->json()['data']['createRegion'];
        $warehouseResponse = $this->createWarehouses($regionResponse['id'])->json()['data']['createWarehouse'];
        $productResponse = $this->createProduct()->json()['data']['createProduct'];
        $region = Regions::find($regionResponse['id']);
        $company = $region->company;

        $warehouseData = [
            'id' => $warehouseResponse['id'],
        ];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: $warehouseData
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
        $endDate = now()->subDays(1);

        $data = [
            'email' => fake()->email(),
            'region_id' => $region->getId(),
            'metadata' => [
                'data' => [
                    'start_at' => now()->subDays(2)->toDateTimeString(),
                    'end_at' => now()->subDays(1)->toDateTimeString(),
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
        ]);

        $createOrderResponse = $response->json()['data']['createDraftOrder'];

        $extendedEndAt = $endDate->addMinutes(30)->toDateTimeString();

        $response = $this->graphQL('
            mutation updateOrder($id: ID!, $input: UpdateOrderInput!) {
                updateOrder(id: $id, input: $input) {
                    order { 
                        id
                        metadata
                        items {
                            id
                            variant {
                                id
                            }
                        }
                    }
                }
            }
        ', [
            'id' => $createOrderResponse['id'],
            'input' => [
                'items' => [
                    [
                        'variant_id' => $variantResponse['id'],
                        'quantity' => 2,
                    ],
                ],
                'metadata' => [
                    'data' => [
                        'end_at' => $extendedEndAt,
                    ],
                ],
            ],
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]);

        $orderData = $response->json()['data']['updateOrder']['order'];
        $order = Order::find($orderData['id']);

        $this->assertEquals($extendedEndAt, $order->metadata['data']['end_at']);
        $this->assertEquals($data['metadata']['data']['start_at'], $order->metadata['data']['start_at']);
        $this->assertCount(1, $order->items);
        $this->assertEquals(2, $order->items[0]->quantity);
    }

    public function testUpdateOrderWithoutItems()
    {
        $app = app(Apps::class);
        $regionResponse = $this->createRegion()->json()['data']['createRegion'];
        $warehouseResponse = $this->createWarehouses($regionResponse['id'])->json()['data']['createWarehouse'];
        $productResponse = $this->createProduct()->json()['data']['createProduct'];
        $region = Regions::find($regionResponse['id']);
        $company = $region->company;

        $warehouseData = [
            'id' => $warehouseResponse['id'],
        ];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: $warehouseData
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
        $endDate = now()->subDays(1);

        $data = [
            'email' => fake()->email(),
            'region_id' => $region->getId(),
            'metadata' => [
                'data' => [
                    'start_at' => now()->subDays(2)->toDateTimeString(),
                    'end_at' => now()->subDays(1)->toDateTimeString(),
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
        ]);

        $createOrderResponse = $response->json()['data']['createDraftOrder'];

        $extendedEndAt = $endDate->addMinutes(30)->toDateTimeString();

        $response = $this->graphQL('
            mutation updateOrder($id: ID!, $input: UpdateOrderInput!) {
                updateOrder(id: $id, input: $input) {
                    order { 
                        id
                        metadata
                        items {
                            id
                            variant {
                                id
                            }
                        }
                    }
                }
            }
        ', [
            'id' => $createOrderResponse['id'],
            'input' => [
                'metadata' => [
                    'data' => [
                        'end_at' => $extendedEndAt,
                    ],
                ],
            ],
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]);

        $orderData = $response->json()['data']['updateOrder']['order'];
        $order = Order::find($orderData['id']);

        $this->assertEquals($extendedEndAt, $order->metadata['data']['end_at']);
        $this->assertEquals($data['metadata']['data']['start_at'], $order->metadata['data']['start_at']);
        $this->assertCount(1, $order->items);
        $this->assertEquals(1, $order->items[0]->quantity);
    }

    public function testUpdateOrderFulfillmentStatus()
    {
        $app = app(Apps::class);
        $regionResponse = $this->createRegion()->json()['data']['createRegion'];
        $warehouseResponse = $this->createWarehouses($regionResponse['id'])->json()['data']['createWarehouse'];
        $productResponse = $this->createProduct()->json()['data']['createProduct'];
        $region = Regions::find($regionResponse['id']);
        $company = $region->company;

        $warehouseData = [
            'id' => $warehouseResponse['id'],
        ];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: $warehouseData
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
        $endDate = now()->subDays(1);

        $data = [
            'email' => fake()->email(),
            'region_id' => $region->getId(),
            'metadata' => [
                'data' => [
                    'start_at' => now()->subDays(2)->toDateTimeString(),
                    'end_at' => now()->subDays(1)->toDateTimeString(),
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
        ]);

        $createOrderResponse = $response->json()['data']['createDraftOrder'];

        $extendedEndAt = $endDate->addMinutes(30)->toDateTimeString();

        $response = $this->graphQL('
            mutation updateOrder($id: ID!, $input: UpdateOrderInput!) {
                updateOrder(id: $id, input: $input) {
                    order { 
                        id
                        metadata
                        fulfillment_status
                        items {
                            id
                            variant {
                                id
                            }
                        }
                    }
                }
            }
        ', [
            'id' => $createOrderResponse['id'],
            'input' => [
                'fulfillment_status' => 'fulfilled',
                'statius' => 'completed',
                'payment_status' => 'paid',
            ],
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]);

        $orderData = $response->json()['data']['updateOrder']['order'];
        $order = Order::find($orderData['id']);

        $this->assertEquals('fulfilled', $order->fulfillment_status);
        $this->assertEquals('completed', $order->status);
        $this->assertEquals('paid', $order->payment_status);
    }

    public function testCreateOrderWithDecimalQuantity()
    {
        $app = app(Apps::class);
        $regionResponse = $this->createRegion()->json()['data']['createRegion'];
        $warehouseResponse = $this->createWarehouses($regionResponse['id'])->json()['data']['createWarehouse'];
        $productResponse = $this->createProduct()->json()['data']['createProduct'];
        $region = Regions::find($regionResponse['id']);
        $company = $region->company;

        $warehouseData = [
            'id' => $warehouseResponse['id'],
        ];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: $warehouseData
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
        $endDate = now()->subDays(1);

        $data = [
            'email' => fake()->email(),
            'region_id' => $region->getId(),
            'metadata' => [
                'data' => [
                    'start_at' => now()->subDays(2)->toDateTimeString(),
                    'end_at' => now()->subDays(1)->toDateTimeString(),
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
                    'quantity' => 2.5,
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
        ]);

        $orderData = $response->json()['data']['createDraftOrder'];
        $order = Order::find($orderData['id']);

        $this->assertEquals(2.5, $order->items[0]->quantity);
    }

    public function testUpdateOrderMetadataMergesCorrectly()
    {
        $app = app(Apps::class);
        $regionResponse = $this->createRegion()->json()['data']['createRegion'];
        $warehouseResponse = $this->createWarehouses($regionResponse['id'])->json()['data']['createWarehouse'];
        $productResponse = $this->createProduct()->json()['data']['createProduct'];
        $region = Regions::find($regionResponse['id']);
        $company = $region->company;

        $warehouseData = [
            'id' => $warehouseResponse['id'],
        ];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: $warehouseData
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

        // Create order with initial metadata
        $initialMetadata = [
            'data' => [
                'start_at' => now()->subDays(2)->toDateTimeString(),
                'end_at' => now()->subDays(1)->toDateTimeString(),
                'customer_notes' => 'Initial order notes',
            ],
            'tracking_id' => 'TRACK123',
        ];

        $data = [
            'email' => fake()->email(),
            'region_id' => $region->getId(),
            'metadata' => $initialMetadata,
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
        ]);

        $createOrderResponse = $response->json()['data']['createDraftOrder'];
        $orderId = $createOrderResponse['id'];

        // Update with partial metadata - should merge, not replace
        $updateMetadata = [
            'data' => [
                'end_at' => now()->addDays(5)->toDateTimeString(),
                'warehouse_code' => 'WH-001',
                'new_field' => 'New Value',
            ],
            'another_top_level_field' => 'Another Value',
        ];

        $response = $this->graphQL('
            mutation updateOrder($id: ID!, $input: UpdateOrderInput!) {
                updateOrder(id: $id, input: $input) {
                    order { 
                        id
                        metadata
                    }
                }
            }
        ', [
            'id' => $orderId,
            'input' => [
                'metadata' => $updateMetadata,
            ],
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]);

        $orderData = $response->json()['data']['updateOrder']['order'];
        $order = Order::find($orderData['id']);

        // Validate metadata merging
        // Original 'data' fields should be preserved
        $this->assertEquals($initialMetadata['data']['start_at'], $order->metadata['data']['start_at']);
        $this->assertEquals($initialMetadata['data']['customer_notes'], $order->metadata['data']['customer_notes']);
        $this->assertEquals($updateMetadata['data']['new_field'], $order->metadata['data']['new_field']);
        $this->assertEquals($updateMetadata['another_top_level_field'], $order->metadata['another_top_level_field']);

        // Updated 'data' fields should be changed
        $this->assertEquals($updateMetadata['data']['end_at'], $order->metadata['data']['end_at']);
        $this->assertEquals($updateMetadata['data']['warehouse_code'], $order->metadata['data']['warehouse_code']);

        // Top-level metadata should be preserved
        $this->assertEquals($initialMetadata['tracking_id'], $order->metadata['tracking_id']);

        // Verify the complete structure
        $this->assertIsArray($order->metadata);
        $this->assertIsArray($order->metadata['data']);
        $this->assertCount(5, $order->metadata['data']); // start_at, end_at, customer_notes, warehouse_code, new_field
    }

    public function testUpdateOrderMetadataWithOverrideFlag()
    {
        $app = app(Apps::class);
        $regionResponse = $this->createRegion()->json()['data']['createRegion'];
        $warehouseResponse = $this->createWarehouses($regionResponse['id'])->json()['data']['createWarehouse'];
        $productResponse = $this->createProduct()->json()['data']['createProduct'];
        $region = Regions::find($regionResponse['id']);
        $company = $region->company;

        $warehouseData = [
            'id' => $warehouseResponse['id'],
        ];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: $warehouseData
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

        // Create order with initial metadata
        $initialMetadata = [
            'data' => [
                'start_at' => now()->subDays(2)->toDateTimeString(),
                'end_at' => now()->subDays(1)->toDateTimeString(),
                'customer_notes' => 'Initial order notes',
            ],
            'tracking_id' => 'TRACK123',
            'old_field' => 'This should be removed',
        ];

        $data = [
            'email' => fake()->email(),
            'region_id' => $region->getId(),
            'metadata' => $initialMetadata,
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
        ]);

        $createOrderResponse = $response->json()['data']['createDraftOrder'];
        $orderId = $createOrderResponse['id'];

        // Update with metadata_action = true - should replace all metadata
        $overrideMetadata = [
            'data' => [
                'warehouse_code' => 'WH-002',
                'new_data_field' => 'Brand new data',
            ],
            'new_top_level' => 'New top level value',
        ];

        $response = $this->graphQL('
            mutation updateOrder($id: ID!, $input: UpdateOrderInput!) {
                updateOrder(id: $id, input: $input) {
                    order { 
                        id
                        metadata
                    }
                }
            }
        ', [
            'id' => $orderId,
            'input' => [
                'metadata' => $overrideMetadata,
                'metadata_action' => 'REPLACE',
            ],
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]);

        $orderData = $response->json()['data']['updateOrder']['order'];

        $order = Order::find($orderData['id']);

        // Validate metadata override (replace mode)
        // Old fields should be completely gone
        $this->assertTrue(isset($order->metadata['tracking_id']));
        $this->assertTrue(isset($order->metadata['old_field']));
        $this->assertFalse(isset($order->metadata['data']['start_at']));
        $this->assertFalse(isset($order->metadata['data']['end_at']));
        $this->assertFalse(isset($order->metadata['data']['customer_notes']));

        // Only new metadata should exist
        $this->assertEquals($overrideMetadata['data']['warehouse_code'], $order->metadata['data']['warehouse_code']);
        $this->assertEquals($overrideMetadata['data']['new_data_field'], $order->metadata['data']['new_data_field']);
        $this->assertEquals($overrideMetadata['new_top_level'], $order->metadata['new_top_level']);

        // Verify the complete structure - should only have new fields
        $this->assertIsArray($order->metadata);
        $this->assertIsArray($order->metadata['data']);
        $this->assertCount(2, $order->metadata['data']); // warehouse_code, new_data_field
        $this->assertCount(4, $order->metadata); // data, new_top_level, tracking_id, old_field
    }

    public function testUpdateOrderCustomer()
    {
        $app = app(Apps::class);
        $regionResponse = $this->createRegion()->json()['data']['createRegion'];
        $warehouseResponse = $this->createWarehouses($regionResponse['id'])->json()['data']['createWarehouse'];
        $productResponse = $this->createProduct()->json()['data']['createProduct'];
        $region = Regions::find($regionResponse['id']);
        $company = $region->company;

        $peopleId = People::factory()
                ->withAppId($app->getId())
                ->withCompanyId($company->getId())
                ->withContacts()
                ->create()
                ->getId();

        $warehouseData = [
            'id' => $warehouseResponse['id'],
        ];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: $warehouseData
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

        // Create order with initial metadata
        $initialMetadata = [
            'data' => [
                'start_at' => now()->subDays(2)->toDateTimeString(),
                'end_at' => now()->subDays(1)->toDateTimeString(),
                'customer_notes' => 'Initial order notes',
            ],
            'tracking_id' => 'TRACK123',
        ];

        $data = [
            'email' => fake()->email(),
            'region_id' => $region->getId(),
            'metadata' => $initialMetadata,
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
        ]);

        $createOrderResponse = $response->json()['data']['createDraftOrder'];
        $orderId = $createOrderResponse['id'];

        $response = $this->graphQL('
            mutation orderChangeCustomer($order_id: ID!, $customer_id: ID!) {
                orderChangeCustomer(order_id: $order_id, customer_id: $customer_id) 
            }
        ', [
            'order_id' => $orderId,
            'customer_id' => $peopleId,
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
             AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
        ]);

        $orderData = $response->json()['data']['orderChangeCustomer'];
        $order = Order::find($orderId);

        $this->assertTrue($order->people_id === $peopleId);
        $this->assertTrue($orderData);
    }
}
