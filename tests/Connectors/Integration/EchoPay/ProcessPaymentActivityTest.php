<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\EchoPay;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\Enums\ConfigurationEnum;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum;
use Kanvas\Connectors\EchoPay\Handlers\EchoPayHandler;
use Kanvas\Connectors\EchoPay\Workflows\Activities\ProcessPaymentActivity;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\GraphQL\Souk\Traits\PaymentCases;
use Tests\TestCase;

final class ProcessPaymentActivityTest extends TestCase
{
    use HasIntegrationCompany;
    use InventoryCases;
    use PaymentCases;

    public function testOrderCreationWorkflow(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $region = Regions::getDefault($company ?? $company, $app);

        $orderTypeName = 'paso_rapido';

        $app->set(ConfigurationEnum::CLIENT_ID->value, env('TEST_ECHO_PAY_CLIENT_ID'));
        $app->set(ConfigurationEnum::SECRET->value, env('TEST_ECHO_PAY_SECRET'));
        $app->set(ConfigurationEnum::APP_TOKEN->value, env('TEST_ECHO_PAY_APP_TOKEN'));
        $app->set(ConfigurationEnum::MERCHANT_ID->value, env('TEST_ECHO_PAY_MERCHANT_ID'));
        $app->set(ConfigurationEnum::MERCHANT_KEY->value, env('TEST_ECHO_PAY_MERCHANT_KEY'));
        $app->set(ConfigurationEnum::MERCHANT_SECRET->value, env('TEST_ECHO_PAY_MERCHANT_SECRET'));

        $app->set($orderTypeName . '_' . CustomFieldEnum::ECHO_PAY_MERCHANT_KEY->value, env('TEST_ECHO_PAY_MERCHANT_SERVICE_KEY'));
        $app->set($orderTypeName . '_' . CustomFieldEnum::ECHO_PAY_CHANNEL_CODE->value, env('TEST_ECHO_PAY_CHANNEL_CODE'));
        $app->set($orderTypeName . '_' . CustomFieldEnum::ECHO_PAY_SERVICE_CODE->value, env('TEST_ECHO_PAY_SERVICE_CODE'));
        $app->set($orderTypeName . '_' . CustomFieldEnum::ECHO_PAY_SERVICE_TYPE_ID->value, env('TEST_ECHO_PAY_SERVICE_TYPE_ID'));
        $app->set($orderTypeName . '_' . CustomFieldEnum::ECHO_PAY_CONTRACT->value, env('TEST_ECHO_PAY_CONTRACT'));

        $this->setIntegration(
            $app,
            IntegrationsEnum::ECHO_PAY,
            EchoPayHandler::class,
            $company,
            $user
        );

        $warehouseResponse = $this->createWarehouses((string) $region->getId())->json()['data']['createWarehouse'];
        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'slots',
                'value' => 100,
            ],
        ])->json()['data']['createProduct'];

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

        $paymentMethod = $this->addPaymentMethod($company, $this->getCardData());

        $data = [
            'cartId' => 0,
            'customer' => [
                'email' => fake()->email(),
            ],
            'order_type' => $orderTypeName,
            'metadata' => [
                'data' => [
                    'paso_rapido_tag' => '317169',
                    'payment_methods_id' => $paymentMethod['id'],
                    'payment_date' => now()->toDateTimeString(),
                ],
            ],
            'items' => [
                [
                    'variant_id' => $variantResponse['id'],
                    'quantity' => 1,
                    'price' => 100,
                ],
            ],
            'reference' => 'Recarga de paso rapido 2',
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
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
            'X-Kanvas-App' => $app->key,
        ]);

        $order = $response->json('data.createOrderFromCart.order');

        $order = Order::fromApp($app)->find($order['id']);

        $activity = new ProcessPaymentActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $payment = $order->payments()->first();
        $result = $activity->execute($payment, $app, []);
        if ($result['status'] != 'success') {
            $this->fail($result['message']);
        }
        $order->refresh();
        $this->assertEquals($result['status'], 'success');
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('data', $result);
    }
}
