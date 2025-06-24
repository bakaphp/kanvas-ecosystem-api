<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Models\Order;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class OrderPaymentLinkTest extends TestCase
{
    use InventoryCases;

    protected function createOrderForPaymentTest(): Order
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        // Create product and variant
        $productData = new Product(
            app: $app,
            company: $company,
            user: $user,
            name: fake()->name(),
            sku: fake()->unique()->word(),
            warehouses: [[
                'quantity' => 10,
                'price' => 99.99,
            ]]
        );

        $product = (new CreateProductAction($productData, $user))->execute();
        $variant = $product->variants()->first();
        $warehouse = Warehouses::fromApp($app)->fromCompany($company)->first();
        $channel = Channels::fromApp($app)->fromCompany($company)->first();

        // Set pricing
        $variant->updatePriceInWarehouse($warehouse, 99.99);
        $variant->updatePriceInChannel($channel, 99.99);

        // Disable notifications for testing
        $app->del(ConfigurationEnum::SEND_NEW_ORDER_NOTIFICATION->value);
        $app->del(ConfigurationEnum::SEND_NEW_ORDER_TO_OWNER_NOTIFICATION->value);

        // Create order data
        $data = [
            'cartId' => 'test-cart',
            'customer' => [
                'email' => $user->email,
                'phone' => $user->phone ?? fake()->phoneNumber(),
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
                'test_order' => true,
                'user_company_id' => $company->getId(),
            ],
        ];

        // Create order via GraphQL
        $response = $this->graphQL('
            mutation createOrderFromCart($input: OrderCartInput!) {
                createOrderFromCart(input: $input) {
                    order {
                        id
                        total_gross_amount
                        currency
                        status
                    }
                }
            }
        ', [
            'input' => $data,
        ]);

        $orderData = $response->json()['data']['createOrderFromCart']['order'];

        return Order::find($orderData['id']);
    }

    public function testGenerateBasicCheckoutSession()
    {
        // Skip test if Stripe is not configured
        $app = app(Apps::class);
        if (empty($app->get('stripe_secret_key'))) {
            $this->markTestSkipped('Stripe secret key not configured');
        }
        $app->keys()->first()->user()->firstOrFail()->assign(RolesEnums::OWNER->value);
        $order = $this->createOrderForPaymentTest();

        $response = $this->graphQL(
            '
            mutation generateCheckoutSession($order_id: ID!, $options: PaymentLinkOptionsInput) {
                generateCheckoutSession(order_id: $order_id, options: $options) {
                    status
                    payment_url
                    payment_link_id
                    session_id
                    provider
                    message
                }
            }
        ',
            [
            'order_id' => $order->id,
            'options' => [
                'success_url' => "https://yoursite.com/orders/{$order->id}/success",
                'cancel_url' => "https://yoursite.com/orders/{$order->id}/cancel",
            ],
        ],
            [],
            [
                AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id,
            ]
        );

        $response->assertJson([
            'data' => [
                'generateCheckoutSession' => [
                    'status' => 'success',
                    'provider' => 'stripe',
                    'payment_link_id' => null, // Checkout sessions don't have payment link IDs
                ],
            ],
        ]);

        $responseData = $response->json()['data']['generateCheckoutSession'];

        // Verify response structure
        $this->assertEquals('success', $responseData['status']);
        $this->assertEquals('stripe', $responseData['provider']);
        $this->assertNotNull($responseData['payment_url']);
        $this->assertNotNull($responseData['session_id']);
        $this->assertStringStartsWith('cs_', $responseData['session_id']);

        // Verify order metadata was updated
        $order->refresh();
        $this->assertNotNull($order->getMetadata('stripe_session_id'));
        $this->assertNotNull($order->getMetadata('stripe_checkout_url'));
    }
}
