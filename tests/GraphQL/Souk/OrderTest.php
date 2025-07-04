<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Models\Order;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\GraphQL\Souk\Traits\PaymentCases;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use InventoryCases;
    use PaymentCases;

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
                }
            }
        ', [
            'input' => $data,
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]);

        $response->assertSuccessful();
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
            ]
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
                    'client_secret' => $paymentIntentId
                ]
            ],
        ];

        // Perform GraphQL mutation to create a draft order
        $response = $this->graphQL('
            mutation createOrderFromCart($input: OrderCartInput!) {
                createOrderFromCart(input: $input) {
                    order {
                        id
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
            ],
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]);

        $orderData = $response->json()['data']['updateOrder']['order'];
        $order = Order::find($orderData['id']);

        $this->assertEquals('fulfilled', $order->fulfillment_status);
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

    public function testInvalidOrder()
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
            ]
        ];

        $paymentIntentId = $this->createTestFailingPaymentIntent($items[0], $app)->id;
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
                    'client_secret' => $paymentIntentId
                ]
            ],
        ];

        // Perform GraphQL mutation to create a draft order
        $response = $this->graphQL('
            mutation createOrderFromCart($input: OrderCartInput!) {
                createOrderFromCart(input: $input) {
                    order {
                        id
                    }
                }
            }
        ', [
            'input' => $data,
        ]);

        $response->assertJsonFragment([
            'extensions' => [
                'reason' => 'requires_payment_method'
            ]
        ]);
    }
}
