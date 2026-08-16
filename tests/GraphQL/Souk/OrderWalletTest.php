<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentMethodTypesEnum;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Enums\RefundStatusEnum;
use Kanvas\Souk\Payments\Models\PaymentLogs;
use Kanvas\Souk\Payments\Models\PaymentRefund;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Wallet\Actions\AddFundsToUserWalletAction;
use Kanvas\Souk\Wallet\Actions\PayFromWalletAction;
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
            sku: fake()->unique()->uuid(),
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

    public function testPayFromWalletThrowsValidationExceptionOnEmptyWallet(): void
    {
        // Fresh user/company so the wallet balance is isolated from the other tests in this class
        $this->actingAs($this->createUser(), 'api');

        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);
        $app->set(WalletConfigurationEnum::USE_USER_WALLET->value, false);

        $this->setupInventory($app, $company, $user);

        $productData = new Product(
            app: $app,
            company: $company,
            user: $user,
            name: fake()->name(),
            sku: fake()->unique()->uuid(),
            warehouses: [[
                'quantity' => 10,
                'price' => 100,
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

        // Exactly enough for one order — the order below drains the wallet to 0
        $wallet = $company->createAppWallet($app, ['name' => 'default']);
        $wallet->deposit(20000, [
            'description' => 'Balance for order testing',
            'slug' => 'empty-wallet-deposit',
        ]);

        $response = $this->graphQL('
            mutation createOrderFromWalletCart($input: OrderCartInput!) {
                createOrderFromWalletCart(input: $input) {
                    order {
                        id
                    }
                }
            }
        ', [
            'input' => [
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
            ],
        ]);

        $order = Order::findOrFail($response->json('data.createOrderFromWalletCart.order.id'));

        $wallet->refresh();
        $this->assertEquals(0, $wallet->balanceFloat);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Insufficient wallet balance to complete the order.');

        new PayFromWalletAction(order: $order)->execute();
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
            sku: fake()->unique()->uuid(),
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
            sku: fake()->unique()->uuid(),
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
        $freshUser = $this->createUser();
        $this->actingAs($freshUser, 'api');

        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $this->setupInventory($app, $company, $user);

        // Create a product
        $productData = new Product(
            app: $app,
            company: $company,
            user: $user,
            name: fake()->name(),
            sku: fake()->unique()->uuid(),
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
        $freshUser = $this->createUser();
        $this->actingAs($freshUser, 'api');

        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $this->setupInventory($app, $company, $user);

        // Create a product
        $productData = new Product(
            app: $app,
            company: $company,
            user: $user,
            name: fake()->name(),
            sku: fake()->unique()->uuid(),
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
            sku: fake()->unique()->uuid(),
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
        $this->assertLessThan(
            $initialBalance,
            $userWallet->balanceFloat,
            'User wallet balance should be reduced after order'
        );

        // Cleanup: disable user wallet mode
        $app->del(WalletConfigurationEnum::USE_USER_WALLET->value);
    }

    public function testBuyCoinsAddsToUserWallet()
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $coinAmount = 500.00;

        $productData = new Product(
            app: $app,
            company: $company,
            user: $user,
            name: 'Wallet Coin Pack ' . fake()->word(),
            sku: fake()->unique()->uuid(),
            warehouses: [[
                'quantity' => 100,
                'price' => 9.99,
            ]]
        );
        $product = (new CreateProductAction($productData, $user))->execute();
        $variant = $product->variants()->first();

        // Add wallet-coin attributes so the system recognizes this as a coin product
        $variant->addAttribute('wallet-coin', 'true');
        $variant->addAttribute('wallet-coin-amount', (string) $coinAmount);

        $warehouse = Warehouses::fromApp($app)->fromCompany($company)->first();
        $channel = Channels::fromApp($app)->fromCompany($company)->first();
        $variant->updatePriceInWarehouse($warehouse, 9.99);
        $variant->updatePriceInChannel($channel, 9.99);

        $app->del(ConfigurationEnum::SEND_NEW_ORDER_NOTIFICATION->value);
        $app->del(ConfigurationEnum::SEND_NEW_ORDER_TO_OWNER_NOTIFICATION->value);

        // Get user wallet balance before
        $userWallet = $user->createAppWallet($app, ['name' => 'default']);
        $balanceBefore = (float) $userWallet->balanceFloat;

        // Create order with the coin variant using regular cart (not wallet cart)
        $data = [
            'cartId' => 'default',
            'customer' => [
                'email' => $user->email,
                'phone' => $user->phone,
            ],
            'items' => [
                [
                    'variant_id' => $variant->getId(),
                    'quantity' => 1,
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
            mutation createOrderFromCart($input: OrderCartInput!) {
                createOrderFromCart(input: $input) {
                    order {
                        id
                    }
                }
            }
        ', ['input' => $data]);

        $response->assertSuccessful();
        $orderId = $response->json('data.createOrderFromCart.order.id');
        $this->assertNotNull($orderId);

        // Simulate the workflow activity that runs after payment: AddFundsToUserWalletAction
        $order = Order::getById((int) $orderId);
        $transaction = new AddFundsToUserWalletAction(order: $order)->execute();

        $this->assertNotNull($transaction);
        $this->assertEquals($coinAmount, (float) $transaction->amountFloat);

        // Verify user wallet balance increased
        $userWallet->refresh();
        $this->assertEquals(
            $balanceBefore + $coinAmount,
            (float) $userWallet->balanceFloat,
            'User wallet balance should increase by coin amount after purchase'
        );
    }

    private function getAppKeyHeader(): array
    {
        $app = app(Apps::class);

        return [AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id];
    }

    private function createWalletPaidOrder(float $orderTotal = 200.0): array
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        // Ensure company wallet mode (not user wallet)
        $app->del(WalletConfigurationEnum::USE_USER_WALLET->value);

        $wallet = $company->createAppWallet($app, ['name' => 'default']);
        $wallet->deposit((int) ($orderTotal * 100), [
            'description' => 'Deposit for refund test',
        ]);

        $people = \Kanvas\Guild\Customers\Models\People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();

        $order = Order::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->withPeopleId($people->getId())
            ->create([
                'total_gross_amount' => $orderTotal,
                'total_net_amount' => $orderTotal,
                'status' => 'completed',
                'payment_status' => 'paid',
            ]);

        $wallet->withdrawFloat($orderTotal, [
            'order_id' => $order->getId(),
            'order_number' => (string) $order->order_number,
            'type' => 'order_payment',
            'description' => 'Wallet payment for order #' . (string) $order->order_number,
        ]);

        $order->addTag(WalletConfigurationEnum::WALLET_CREDIT_TAG->value);
        $wallet->refresh();

        return ['order_id' => $order->getId(), 'wallet' => $wallet, 'order_total' => $orderTotal];
    }

    public function testRefundFullWalletOrder(): void
    {
        $result = $this->createWalletPaidOrder(200.0);
        $wallet = $result['wallet'];
        $balanceAfterOrder = (float) $wallet->balanceFloat;

        $response = $this->graphQL('
            mutation refundOrderToWallet($input: WalletRefundInput!) {
                refundOrderToWallet(input: $input) {
                    balance
                    message
                }
            }
        ', [
            'input' => [
                'order_id' => $result['order_id'],
                'reason' => 'Test full refund',
            ],
        ], [], $this->getAppKeyHeader());

        $response->assertSuccessful();
        $response->assertJsonStructure([
            'data' => [
                'refundOrderToWallet' => ['balance', 'message'],
            ],
        ]);

        $wallet->refresh();
        $this->assertGreaterThan($balanceAfterOrder, (float) $wallet->balanceFloat);

        /** @var Order $order */
        $order = Order::getById((int) $result['order_id']);
        $this->assertEquals(200.0, $order->get('wallet_refund_total'));
        $refunds = $order->get('wallet_refunds');
        $this->assertCount(1, $refunds);
        $this->assertEquals('Test full refund', $refunds[0]['reason']);
    }

    public function testRefundPartialWalletOrder(): void
    {
        $result = $this->createWalletPaidOrder(200.0);
        $wallet = $result['wallet'];
        $balanceAfterOrder = (float) $wallet->balanceFloat;

        $response = $this->graphQL('
            mutation refundOrderToWallet($input: WalletRefundInput!) {
                refundOrderToWallet(input: $input) {
                    balance
                    message
                }
            }
        ', [
            'input' => [
                'order_id' => $result['order_id'],
                'amount' => 50.0,
                'reason' => 'Partial refund',
            ],
        ], [], $this->getAppKeyHeader());

        $response->assertSuccessful();

        $wallet->refresh();
        $this->assertEquals($balanceAfterOrder + 50.0, (float) $wallet->balanceFloat);

        /** @var Order $order */
        $order = Order::getById((int) $result['order_id']);
        $this->assertEquals(50.0, $order->get('wallet_refund_total'));
        $refunds = $order->get('wallet_refunds');
        $this->assertCount(1, $refunds);
        $this->assertEquals(50.0, $refunds[0]['amount']);
    }

    public function testRefundWalletOrderExceedingAmount(): void
    {
        $result = $this->createWalletPaidOrder(200.0);

        $response = $this->graphQL('
            mutation refundOrderToWallet($input: WalletRefundInput!) {
                refundOrderToWallet(input: $input) {
                    balance
                    message
                }
            }
        ', [
            'input' => [
                'order_id' => $result['order_id'],
                'amount' => 99999.0,
            ],
        ], [], $this->getAppKeyHeader());

        $response->assertJsonFragment([
            'message' => 'Refund amount exceeds maximum refundable. Max remaining: 200',
        ]);
    }

    public function testDoubleFullRefundPrevention(): void
    {
        $result = $this->createWalletPaidOrder(200.0);

        $this->graphQL('
            mutation refundOrderToWallet($input: WalletRefundInput!) {
                refundOrderToWallet(input: $input) {
                    balance
                    message
                }
            }
        ', [
            'input' => [
                'order_id' => $result['order_id'],
                'reason' => 'First refund',
            ],
        ], [], $this->getAppKeyHeader())->assertSuccessful();

        $response = $this->graphQL('
            mutation refundOrderToWallet($input: WalletRefundInput!) {
                refundOrderToWallet(input: $input) {
                    balance
                    message
                }
            }
        ', [
            'input' => [
                'order_id' => $result['order_id'],
                'reason' => 'Second refund attempt',
            ],
        ], [], $this->getAppKeyHeader());

        $response->assertJsonFragment([
            'message' => 'Order has already been fully refunded',
        ]);
    }

    public function testWalletPaymentCreatesPaymentLog(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $productData = new Product(
            app: $app,
            company: $company,
            user: $user,
            name: fake()->name(),
            sku: fake()->unique()->uuid(),
            warehouses: [[
                'quantity' => 10,
                'price' => 100,
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

        $wallet = $company->createAppWallet($app, ['name' => 'default']);
        $wallet->deposit(100000, [
            'description' => 'Deposit for payment log test',
        ]);

        $response = $this->graphQL('
            mutation createOrderFromWalletCart($input: OrderCartInput!) {
                createOrderFromWalletCart(input: $input) {
                    order {
                        id
                    }
                }
            }
        ', [
            'input' => [
                'cartId' => 'default',
                'customer' => [
                    'email' => $user->email,
                    'phone' => $user->phone,
                ],
                'items' => [
                    [
                        'variant_id' => $variant->getId(),
                        'quantity' => 1,
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
            ],
        ]);

        $response->assertSuccessful();
        $orderId = $response->json('data.createOrderFromWalletCart.order.id');

        $log = PaymentLogs::where('payable_id', $orderId)
            ->where('payable_type', Order::class)
            ->where('status', 'wallet_payment')
            ->first();

        $this->assertNotNull($log, 'Wallet payment should create a payment log');
        $this->assertEquals('wallet_payment', $log->status);
        $this->assertNotNull($log->metadata);
        $this->assertArrayHasKey('amount', $log->metadata);
        $this->assertArrayHasKey('tag', $log->metadata);
    }

    public function testWalletRefundCreatesPaymentLog(): void
    {
        $result = $this->createWalletPaidOrder(200.0);

        $this->graphQL('
            mutation refundOrderToWallet($input: WalletRefundInput!) {
                refundOrderToWallet(input: $input) {
                    balance
                    message
                }
            }
        ', [
            'input' => [
                'order_id' => $result['order_id'],
                'amount' => 75.0,
                'reason' => 'Payment log test refund',
            ],
        ], [], $this->getAppKeyHeader())->assertSuccessful();

        $log = PaymentLogs::where('payable_id', $result['order_id'])
            ->where('payable_type', Order::class)
            ->where('status', 'wallet_refund')
            ->first();

        $this->assertNotNull($log, 'Wallet refund should create a payment log');
        $this->assertEquals('wallet_refund', $log->status);
        $this->assertEquals(75.0, $log->metadata['amount']);
        $this->assertEquals('Payment log test refund', $log->metadata['reason']);
        $this->assertEquals('default', $log->metadata['tag']);
    }

    public function testWalletPaymentLogVisibleViaGraphQL(): void
    {
        $result = $this->createWalletPaidOrder(200.0);

        $this->graphQL('
            mutation refundOrderToWallet($input: WalletRefundInput!) {
                refundOrderToWallet(input: $input) {
                    balance
                }
            }
        ', [
            'input' => [
                'order_id' => $result['order_id'],
                'reason' => 'GraphQL visibility test',
            ],
        ], [], $this->getAppKeyHeader())->assertSuccessful();

        $order = Order::find($result['order_id']);
        $this->assertNotNull($order, 'Order should exist after refund');

        $eloquentLogs = $order->paymentLogs()->get();
        $this->assertNotEmpty($eloquentLogs, 'Payment logs should exist on order after wallet refund');
        $this->assertContains('wallet_refund', $eloquentLogs->pluck('status')->toArray());
    }

    public function testCreateOrderFromWalletUsesVariantWalletCreditAmount()
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);
        $app->set(WalletConfigurationEnum::USE_VARIANT_CREDIT_INSTEAD_OF_VARIANT_PRICE_SLUG->value, true);
        try {
            $productData = new Product(
                app: $app,
                company: $company,
                user: $user,
                name: fake()->name(),
                sku: fake()->unique()->uuid(),
                warehouses: [[
                    'quantity' => 10,
                    'price' => 100.00,
                ]]
            );
            $product = (new CreateProductAction($productData, $user))->execute();
            $variant = $product->variants()->first();
            $variant->addAttribute(WalletConfigurationEnum::VARIANT_WALLET_CREDIT_AMOUNT->value, '25');

            $warehouse = Warehouses::fromApp($app)->fromCompany($company)->first();
            $channel = Channels::fromApp($app)->fromCompany($company)->first();
            $variant->updatePriceInWarehouse($warehouse, 100);
            $variant->updatePriceInChannel($channel, 100);

            $app->del(ConfigurationEnum::SEND_NEW_ORDER_NOTIFICATION->value);
            $app->del(ConfigurationEnum::SEND_NEW_ORDER_TO_OWNER_NOTIFICATION->value);

            $wallet = $company->createAppWallet($app, ['name' => 'default']);
            $wallet->deposit(100000, [
                'description' => 'Initial deposit for variant credit wallet test',
                'slug' => 'variant-credit-wallet-test',
            ]);
            $initialBalance = (float) $wallet->balanceFloat;

            $response = $this->graphQL('
                mutation createOrderFromWalletCart($input: OrderCartInput!) {
                    createOrderFromWalletCart(input: $input) {
                        order {
                            id
                        }
                    }
                }
            ', [
                'input' => [
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
                ],
            ]);

            $response->assertSuccessful();

            $wallet->refresh();
            $this->assertEquals(
                $initialBalance - 50.0,
                (float) $wallet->balanceFloat,
                'Wallet should be debited by variant wallet credit amount (25 x 2), not variant price'
            );
        } finally {
            $app->del(WalletConfigurationEnum::USE_VARIANT_CREDIT_INSTEAD_OF_VARIANT_PRICE_SLUG->value);
        }
    }

    private function placeWalletOrder(float $unitPrice = 100.0, int $quantity = 1): int
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $app->del(WalletConfigurationEnum::USE_USER_WALLET->value);

        $productData = new Product(
            app: $app,
            company: $company,
            user: $user,
            name: fake()->name(),
            sku: fake()->unique()->uuid(),
            warehouses: [[
                'quantity' => 100,
                'price' => $unitPrice,
            ]]
        );
        $product = (new CreateProductAction($productData, $user))->execute();
        $variant = $product->variants()->first();
        $warehouse = Warehouses::fromApp($app)->fromCompany($company)->first();
        $channel = Channels::fromApp($app)->fromCompany($company)->first();
        $variant->updatePriceInWarehouse($warehouse, $unitPrice);
        $variant->updatePriceInChannel($channel, $unitPrice);

        $app->del(ConfigurationEnum::SEND_NEW_ORDER_NOTIFICATION->value);
        $app->del(ConfigurationEnum::SEND_NEW_ORDER_TO_OWNER_NOTIFICATION->value);

        $wallet = $company->createAppWallet($app, ['name' => 'default']);
        $wallet->deposit(100000, [
            'description' => 'Deposit for wallet payment row test',
        ]);

        $response = $this->graphQL('
            mutation createOrderFromWalletCart($input: OrderCartInput!) {
                createOrderFromWalletCart(input: $input) {
                    order {
                        id
                    }
                }
            }
        ', [
            'input' => [
                'cartId' => 'default',
                'customer' => [
                    'email' => $user->email,
                    'phone' => $user->phone,
                ],
                'items' => [
                    [
                        'variant_id' => $variant->getId(),
                        'quantity' => $quantity,
                        'price' => $unitPrice,
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
            ],
        ]);

        $response->assertSuccessful();

        return (int) $response->json('data.createOrderFromWalletCart.order.id');
    }

    public function testWalletPaymentCreatesPaymentRow(): void
    {
        $orderId = $this->placeWalletOrder(100.0, 1);

        $payment = Payments::where('payable_id', $orderId)
            ->where('payable_type', Order::class)
            ->where('payment_method', PaymentMethodTypesEnum::WALLET->value)
            ->first();

        $this->assertNotNull($payment, 'Wallet payment should create a row in the payments table');
        $this->assertEquals(PaymentStatusEnum::PAID->value, $payment->status);
        $this->assertEquals(PaymentMethodTypesEnum::WALLET->value, $payment->payment_method);
        $this->assertEquals(PaymentMethodTypesEnum::WALLET->value, $payment->processor);
        $this->assertGreaterThan(0.0, (float) $payment->amount);

        $log = PaymentLogs::where('payable_id', $orderId)
            ->where('payable_type', Order::class)
            ->where('status', 'wallet_payment')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($payment->getId(), (int) $log->payments_id, 'wallet_payment log must reference the real payment row');
    }

    public function testWalletPaymentCountsTowardOrderPaidAmount(): void
    {
        $orderId = $this->placeWalletOrder(100.0, 1);

        /** @var Order $order */
        $order = Order::getById($orderId);

        $this->assertTrue($order->isPaid(), 'Order should report paid from the payments table');
        $this->assertGreaterThan(0.0, $order->getPaidAmount());
    }

    public function testWalletRefundCreatesPaymentRefundAndReversesPayment(): void
    {
        $orderId = $this->placeWalletOrder(100.0, 1);

        $payment = Payments::where('payable_id', $orderId)
            ->where('payable_type', Order::class)
            ->where('payment_method', PaymentMethodTypesEnum::WALLET->value)
            ->firstOrFail();

        $this->graphQL('
            mutation refundOrderToWallet($input: WalletRefundInput!) {
                refundOrderToWallet(input: $input) {
                    balance
                    message
                }
            }
        ', [
            'input' => [
                'order_id' => $orderId,
                'amount' => (float) $payment->amount,
                'reason' => 'Full wallet refund',
            ],
        ], [], $this->getAppKeyHeader())->assertSuccessful();

        $refund = PaymentRefund::where('payments_id', $payment->getId())->first();

        $this->assertNotNull($refund, 'Wallet refund should create a payment_refunds row against the wallet payment');
        $this->assertEquals(RefundStatusEnum::COMPLETED->value, $refund->status);
        $this->assertEquals((float) $payment->amount, (float) $refund->amount);

        $payment->refresh();
        $this->assertEquals(PaymentStatusEnum::REVERSED->value, $payment->status, 'Fully refunded wallet payment must be reversed');
    }

    public function testPartialWalletRefundKeepsPaymentPaid(): void
    {
        $orderId = $this->placeWalletOrder(100.0, 1);

        $payment = Payments::where('payable_id', $orderId)
            ->where('payable_type', Order::class)
            ->where('payment_method', PaymentMethodTypesEnum::WALLET->value)
            ->firstOrFail();

        $partial = round((float) $payment->amount / 2, 2);

        $this->graphQL('
            mutation refundOrderToWallet($input: WalletRefundInput!) {
                refundOrderToWallet(input: $input) {
                    balance
                    message
                }
            }
        ', [
            'input' => [
                'order_id' => $orderId,
                'amount' => $partial,
                'reason' => 'Partial wallet refund',
            ],
        ], [], $this->getAppKeyHeader())->assertSuccessful();

        $refund = PaymentRefund::where('payments_id', $payment->getId())->first();

        $this->assertNotNull($refund);
        $this->assertEquals($partial, (float) $refund->amount);

        $payment->refresh();
        $this->assertEquals(PaymentStatusEnum::PAID->value, $payment->status, 'Partially refunded wallet payment stays paid');
    }

    public function testWalletRefundWithoutPaymentRowUsesMetadataFallback(): void
    {
        // createWalletPaidOrder bypasses PayFromWalletAction, so the order has no payments row.
        $result = $this->createWalletPaidOrder(200.0);
        $wallet = $result['wallet'];
        $balanceAfterOrder = (float) $wallet->balanceFloat;

        $this->graphQL('
            mutation refundOrderToWallet($input: WalletRefundInput!) {
                refundOrderToWallet(input: $input) {
                    balance
                    message
                }
            }
        ', [
            'input' => [
                'order_id' => $result['order_id'],
                'amount' => 50.0,
                'reason' => 'Fallback refund',
            ],
        ], [], $this->getAppKeyHeader())->assertSuccessful();

        $this->assertSame(
            0,
            PaymentRefund::where('apps_id', app(Apps::class)->getId())
                ->whereHas(
                    'payment',
                    fn ($q) => $q->where('payable_id', $result['order_id'])
                        ->where('payable_type', Order::class)
                )
                ->count(),
            'Orders without a payments row must not create a PaymentRefund'
        );

        $wallet->refresh();
        $this->assertEquals($balanceAfterOrder + 50.0, (float) $wallet->balanceFloat);

        /** @var Order $order */
        $order = Order::getById((int) $result['order_id']);
        $this->assertEquals(50.0, $order->get('wallet_refund_total'));
    }
}
