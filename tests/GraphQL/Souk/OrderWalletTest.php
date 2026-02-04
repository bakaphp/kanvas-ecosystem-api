<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum as WalletConfigurationEnum;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class OrderWalletTest extends TestCase
{
    use InventoryCases;

    public function testCreateOrderFromWallet()
    {
        //$variantWarehouse = VariantsWarehouses::first();
        //$region = $variantWarehouse->warehouse->region;
        //$app = $region->app;
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

        $wallet = $company->createAppWallet($app, ['name' => 'default'])->deposit(100000, [
            'description' => 'Initial deposit for order testing',
            'slug' => 'initial-deposit',
        ]);

        $data = [
            'cartId' => 'default',

            'customer' => [
                'email' => $user->email,
                'phone' => $user->phone,
            ],
            'items' => [
                [
                    'variant_id' => $variant->getId(),
                    'quantity' => 2,
                ],
            ],
            'shipping_address' => [
                'address' => fake()->address(),
                'address_2' => fake()->postcode(),
                'city' => fake()->city(),
                'state' => fake()->state(),
            ],
            'metadata' => [
                'user_company_id' => $company->getId(),
            ],
        ];

        // Perform GraphQL mutation to create a draft order
        $response = $this->graphQL('
            mutation createOrderFromWalletCart($input: OrderCartInput!) {
                createOrderFromWalletCart(input: $input) {
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
                 'createOrderFromWalletCart' => [
                     'order' => [
                         'id' => true, // Check if the order ID is returned
                     ],
                 ],
             ],
         ]);

        // Test the getWalletBalance query
        $balanceResponse = $this->graphQL('
            query getWalletBalance($tag: String!) {
            getWalletBalance(tag: $tag) {
                balance
                message
                data
            }
            }
        ', [
            'tag' => 'default',
        ]);

        $balanceResponse->assertJsonStructure([
            'data' => [
            'getWalletBalance' => [
                'balance',
                'message',
                'data',
            ],
            ],
        ]);
    }

    public function testCreateOrderWithWalletCreditDiscount()
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        // Create a product
        $productData = new Product(
            app: $app,
            company: $company,
            user: $user,
            name: fake()->name(),
            sku: fake()->unique()->word(),
            warehouses: [[
                'quantity' => 100,
                'price' => 100,
            ]],
        );
        $product = (new CreateProductAction($productData, $user))->execute();
        $variant = $product->variants()->first();
        $warehouse = Warehouses::fromApp($app)->fromCompany($company)->first();
        $channel = Channels::fromApp($app)->fromCompany($company)->first();
        $variant->updatePriceInWarehouse($warehouse, 100);
        $variant->updatePriceInChannel($channel, 100);

        $app->del(ConfigurationEnum::SEND_NEW_ORDER_NOTIFICATION->value);
        $app->del(ConfigurationEnum::SEND_NEW_ORDER_TO_OWNER_NOTIFICATION->value);

        // Deposit funds to company wallet
        $wallet = $user->createAppWallet($app, ['name' => 'default']);
        $wallet->deposit(5000, [
            'description' => 'Test wallet credit',
            'slug' => 'test-deposit',
        ]);

        $initialBalance = $wallet->balanceFloat;
        $this->assertEquals(50, $initialBalance);

        // Add item to cart (product costs $100)
        $addToCartResponse = $this->graphQL('
            mutation addToCart($items: [CartItemInput!]!) {
                addToCart(items: $items) {
                    id
                    name
                    price
                    quantity
                }
            }
        ', [
            'items' => [
                [
                    'variant_id' => $variant->getId(),
                    'quantity' => 1,
                ],
            ],
        ]);

        $addToCartResponse->assertJsonStructure([
            'data' => [
                'addToCart' => [
                    '*' => ['id', 'name', 'price', 'quantity'],
                ],
            ],
        ]);

        // Apply wallet credit to cart (should apply $50)
        $applyWalletResponse = $this->graphQL('
            mutation applyWalletCreditToCart($amount: Float, $tag: String) {
                applyWalletCreditToCart(amount: $amount, tag: $tag) {
                    id
                    items {
                        id
                        name
                        price
                        quantity
                    }
                    subtotal
                    total
                    discounts {
                        code
                        amount
                        total
                    }
                }
            }
        ', [
            'amount' => 50.0,
            'tag' => 'default',
        ]);

        $applyWalletResponse->assertJsonStructure([
            'data' => [
                'applyWalletCreditToCart' => [
                    'id',
                    'items',
                    'subtotal',
                    'total',
                    'discounts',
                ],
            ],
        ]);

        // Verify the cart total is reduced by wallet credit
        $cartTotal = $applyWalletResponse->json('data.applyWalletCreditToCart.total');
        $this->assertEquals(50, $cartTotal, 'Cart total should be $50 after applying $50 wallet credit');

        $orderData = [
          'cartId' => 'default',

          'customer' => [
              'email' => $user->email,
              'phone' => $user->phone,
          ],

          'shipping_address' => [
              'address' => fake()->address(),
              'address_2' => fake()->postcode(),
              'city' => fake()->city(),
              'state' => fake()->state(),
          ],
          'metadata' => [
          ],
        ];

        $orderResponse = $this->graphQL('
            mutation createOrderFromCart($input: OrderCartInput!) {
                createOrderFromCart(input: $input) {
                    order {
                    id
                    order_number
                    total_gross_amount
                    metadata
                    }
                }
            }
        ', [
            'input' => $orderData,
        ]);

        $orderResponse->assertJsonStructure([
            'data' => [
                'createOrderFromCart' => [
                    'order' => [
                        'id',
                        'order_number',
                        'total_gross_amount',
                        'metadata',
                    ],
                ],
            ],
        ]);

        // Verify wallet was debited
        $wallet->refresh();
        $finalBalance = $wallet->balanceFloat;
        $this->assertEquals(0, $finalBalance, 'Wallet should be empty after $50 deduction');

        // Verify order metadata contains wallet credit info
        $orderId = $orderResponse->json('data.createOrderFromCart.order.id');
        $order = Order::findOrFail($orderId);
        $this->assertArrayHasKey('wallet_credit', $order->metadata);
        $this->assertEquals(50, $order->metadata['wallet_credit']['amount']);
        $this->assertEquals('default', $order->metadata['wallet_credit']['tag']);
    }

    public function testRemoveWalletCreditFromCart()
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        // Create a product
        $productData = new Product(
            app: $app,
            company: $company,
            user: $user,
            name: fake()->name(),
            sku: fake()->unique()->word(),
            warehouses: [[
                'quantity' => 100,
                'price' => 100,
            ]],
        );
        $product = (new CreateProductAction($productData, $user))->execute();
        $variant = $product->variants()->first();
        $warehouse = Warehouses::fromApp($app)->fromCompany($company)->first();
        $channel = Channels::fromApp($app)->fromCompany($company)->first();
        $variant->updatePriceInWarehouse($warehouse, 100);
        $variant->updatePriceInChannel($channel, 100);

        $app->del(ConfigurationEnum::SEND_NEW_ORDER_NOTIFICATION->value);
        $app->del(ConfigurationEnum::SEND_NEW_ORDER_TO_OWNER_NOTIFICATION->value);

        // Deposit funds to user wallet
        $wallet = $user->createAppWallet($app, ['name' => 'default']);
        $wallet->deposit(5000, [
            'description' => 'Test wallet credit removal',
            'slug' => 'test-deposit-removal',
        ]);

        // Add item to cart (product costs $100)
        $this->graphQL('
            mutation addToCart($items: [CartItemInput!]!) {
                addToCart(items: $items) {
                    id
                }
            }
        ', [
            'items' => [
                [
                    'variant_id' => $variant->getId(),
                    'quantity' => 1,
                ],
            ],
        ]);

        // Apply wallet credit to cart
        $applyWalletResponse = $this->graphQL('
            mutation applyWalletCreditToCart($amount: Float, $tag: String) {
                applyWalletCreditToCart(amount: $amount, tag: $tag) {
                    id
                    subtotal
                    total
                }
            }
        ', [
            'amount' => 50.0,
            'tag' => 'default',
        ]);

        // Verify wallet credit was applied
        $cartTotalWithCredit = $applyWalletResponse->json('data.applyWalletCreditToCart.total');
        $this->assertEquals(50, $cartTotalWithCredit, 'Cart total should be $50 after applying wallet credit');

        // Remove wallet credit from cart
        $removeWalletResponse = $this->graphQL('
            mutation removeWalletCreditFromCart {
                removeWalletCreditFromCart {
                    id
                    subtotal
                    total
                    discounts {
                        code
                        amount
                        total
                    }
                }
            }
        ');

        $removeWalletResponse->assertJsonStructure([
            'data' => [
                'removeWalletCreditFromCart' => [
                    'id',
                    'subtotal',
                    'total',
                    'discounts',
                ],
            ],
        ]);

        // Verify cart total is back to original amount
        $cartTotalAfterRemoval = $removeWalletResponse->json('data.removeWalletCreditFromCart.total');
        $subtotal = $removeWalletResponse->json('data.removeWalletCreditFromCart.subtotal');

        $this->assertEquals(100, $cartTotalAfterRemoval, 'Cart total should be $100 after removing wallet credit');
        $this->assertEquals(100, $subtotal, 'Cart subtotal should be $100');

        // Verify wallet balance unchanged (no deduction yet since order not created)
        $wallet->refresh();
        $walletBalance = $wallet->balanceFloat;
        $this->assertEquals(50, $walletBalance, 'Wallet balance should remain at $50 since no order was created');
    }

    public function testApplyWalletCreditExceedingBalance()
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        // Create a product
        $productData = new Product(
            app: $app,
            company: $company,
            user: $user,
            name: fake()->name(),
            sku: fake()->unique()->word(),
            warehouses: [[
                'quantity' => 100,
                'price' => 200,
            ]],
        );
        $product = (new CreateProductAction($productData, $user))->execute();
        $variant = $product->variants()->first();
        $warehouse = Warehouses::fromApp($app)->fromCompany($company)->first();
        $channel = Channels::fromApp($app)->fromCompany($company)->first();
        $variant->updatePriceInWarehouse($warehouse, 200);
        $variant->updatePriceInChannel($channel, 200);

        $app->del(ConfigurationEnum::SEND_NEW_ORDER_NOTIFICATION->value);
        $app->del(ConfigurationEnum::SEND_NEW_ORDER_TO_OWNER_NOTIFICATION->value);

        // Deposit only $30 to user wallet
        $wallet = $user->createAppWallet($app, ['name' => 'default']);
        $wallet->deposit(3000, [
            'description' => 'Limited wallet credit',
            'slug' => 'test-deposit-limited',
        ]);

        $initialBalance = $wallet->balanceFloat;
        $this->assertEquals(30, $initialBalance);

        // Add item to cart (product costs $200)
        $this->graphQL('
            mutation addToCart($items: [CartItemInput!]!) {
                addToCart(items: $items) {
                    id
                }
            }
        ', [
            'items' => [
                [
                    'variant_id' => $variant->getId(),
                    'quantity' => 1,
                ],
            ],
        ]);

        // Try to apply $100 wallet credit when wallet only has $30
        $applyWalletResponse = $this->graphQL('
            mutation applyWalletCreditToCart($amount: Float, $tag: String) {
                applyWalletCreditToCart(amount: $amount, tag: $tag) {
                    id
                    subtotal
                    total
                }
            }
        ', [
            'amount' => 100.0,
            'tag' => 'default',
        ]);

        // Verify only $30 was applied (limited by wallet balance)
        $cartTotal = $applyWalletResponse->json('data.applyWalletCreditToCart.total');
        $this->assertEquals(170, $cartTotal, 'Cart total should be $170 ($200 - $30) after applying wallet credit limited by balance');

        // Try to apply wallet credit without specifying amount (should apply all available balance)
        $this->graphQL('
            mutation removeWalletCreditFromCart {
                removeWalletCreditFromCart {
                    id
                }
            }
        ');

        $applyAllResponse = $this->graphQL('
            mutation applyWalletCreditToCart($tag: String) {
                applyWalletCreditToCart(tag: $tag) {
                    id
                    subtotal
                    total
                }
            }
        ', [
            'tag' => 'default',
        ]);

        // Should apply all $30 available
        $cartTotalWithAll = $applyAllResponse->json('data.applyWalletCreditToCart.total');
        $this->assertEquals(170, $cartTotalWithAll, 'Cart total should be $170 when applying all available wallet balance ($30)');

        // Verify wallet balance unchanged (no order created yet)
        $wallet->refresh();
        $this->assertEquals(30, $wallet->balanceFloat, 'Wallet balance should still be $30');
    }

    public function testInsufficientWalletBalanceError()
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        // Create a product
        $productData = new Product(
            app: $app,
            company: $company,
            user: $user,
            name: fake()->name(),
            sku: fake()->unique()->word(),
            warehouses: [[
                'quantity' => 100,
                'price' => 100,
            ]],
        );
        $product = (new CreateProductAction($productData, $user))->execute();
        $variant = $product->variants()->first();
        $warehouse = Warehouses::fromApp($app)->fromCompany($company)->first();
        $channel = Channels::fromApp($app)->fromCompany($company)->first();
        $variant->updatePriceInWarehouse($warehouse, 100);
        $variant->updatePriceInChannel($channel, 100);

        $app->del(ConfigurationEnum::SEND_NEW_ORDER_NOTIFICATION->value);
        $app->del(ConfigurationEnum::SEND_NEW_ORDER_TO_OWNER_NOTIFICATION->value);

        // Create wallet with $0 balance (no deposit)
        $wallet = $user->createAppWallet($app, ['name' => 'default']);

        // Add item to cart
        $this->graphQL('
            mutation addToCart($items: [CartItemInput!]!) {
                addToCart(items: $items) {
                    id
                }
            }
        ', [
            'items' => [
                [
                    'variant_id' => $variant->getId(),
                    'quantity' => 1,
                ],
            ],
        ]);

        // Try to apply wallet credit with $0 balance
        $applyWalletResponse = $this->graphQL('
            mutation applyWalletCreditToCart($amount: Float, $tag: String) {
                applyWalletCreditToCart(amount: $amount, tag: $tag) {
                    id
                }
            }
        ', [
            'amount' => 50.0,
            'tag' => 'default',
        ]);

        // Should get an error about insufficient balance
        $applyWalletResponse->assertJsonFragment([
            'message' => 'Insufficient wallet balance',
        ]);
    }

    public function testCreateOrderFromUserWallet()
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        // Enable user wallet mode
        $app->set(WalletConfigurationEnum::USE_USER_WALLET->value, true);

        $productData = new Product(
            app: $app,
            company: $company,
            user: $user,
            name: fake()->name(),
            sku: fake()->unique()->word(),
            warehouses: [[
                'quantity' => 10,
                'price' => 0.29,
            ]]
        );
        $product = (new CreateProductAction($productData, $user))->execute();
        $variant = $product->variants()->first();
        $warehouse = Warehouses::fromApp($app)->fromCompany($company)->first();
        $channel = Channels::fromApp($app)->fromCompany($company)->first();
        $variant->updatePriceInWarehouse($warehouse, 100);
        $variant->updatePriceInChannel($channel, 100);

        $app->del(ConfigurationEnum::SEND_NEW_ORDER_NOTIFICATION->value);
        $app->del(ConfigurationEnum::SEND_NEW_ORDER_TO_OWNER_NOTIFICATION->value);

        // Deposit to USER wallet (not company)
        $userWallet = $user->createAppWallet($app, ['name' => 'default']);
        $userWallet->deposit(100000, [
            'description' => 'Initial deposit for user wallet order testing',
            'slug' => 'initial-user-deposit',
        ]);

        $initialBalance = $userWallet->balanceFloat;

        $data = [
            'cartId' => 'default',
            'customer' => [
                'email' => $user->email,
                'phone' => $user->phone,
            ],
            'items' => [
                [
                    'variant_id' => $variant->getId(),
                    'quantity' => 2,
                ],
            ],
            'shipping_address' => [
                'address' => fake()->address(),
                'address_2' => fake()->postcode(),
                'city' => fake()->city(),
                'state' => fake()->state(),
            ],
            'metadata' => [
                'user_company_id' => $company->getId(),
            ],
        ];

        $response = $this->graphQL('
            mutation createOrderFromWalletCart($input: OrderCartInput!) {
                createOrderFromWalletCart(input: $input) {
                    order {
                        id
                        total_gross_amount
                    }
                }
            }
        ', [
            'input' => $data,
        ]);

        $response->assertJson([
            'data' => [
                'createOrderFromWalletCart' => [
                    'order' => [
                        'id' => true,
                    ],
                ],
            ],
        ]);

        // Verify user wallet was debited
        $userWallet->refresh();
        $this->assertLessThan($initialBalance, $userWallet->balanceFloat, 'User wallet balance should be reduced after order');

        // Cleanup: disable user wallet mode
        $app->del(WalletConfigurationEnum::USE_USER_WALLET->value);
    }
}
