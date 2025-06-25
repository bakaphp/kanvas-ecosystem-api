<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\EchoPay;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthentication;
use Kanvas\Connectors\EchoPay\Enums\ConfigurationEnum;
use Kanvas\Connectors\EchoPay\Handlers\EchoPayHandler;
use Kanvas\Connectors\Movipass\Actions\ProcessPaymentAction;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\GraphQL\Souk\Traits\PaymentCases;
use Tests\TestCase;

final class ProcessPaymentTest extends TestCase
{
    use HasIntegrationCompany;
    use InventoryCases;
    use PaymentCases;

    public function testProcessPayment(): void
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
        $app->set(ConfigurationEnum::MERCHANT_IDENTIFIER->value, env('TEST_ECHO_PAY_MERCHANT_IDENTIFIER'));
        $app->set(ConfigurationEnum::MERCHANT_KEY->value, env('TEST_ECHO_PAY_MERCHANT_KEY'));
        $app->set(ConfigurationEnum::MERCHANT_SECRET->value, env('TEST_ECHO_PAY_MERCHANT_SECRET'));

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
        $payment = $order->payments()->first();
        $order->set('auth_session_id', '1234567890');

        $processPaymentAction = new ProcessPaymentAction($app, $payment, $order);

        $result = $processPaymentAction->execute(ConsumerAuthentication::from([
                'indicator' => 'vbv',
                'eciRaw' => '05',
                'authenticationResult' => '0',
                'strongAuthentication' => [
                    'OutageExemptionIndicator' => '0',
                ],
                'authenticationStatusMsg' => 'Success',
                'eci' => '05',
                'token' => 'AxjzbwSTlSvEI+byinVHAKUBTyD9dO6A1h04goIQyaSZejFcRGKBWAAAXBJS',
                'cavv' => 'AAIBBYNoEwAAACcKhAJkdQAAAAA=',
                'paresStatus' => 'Y',
                'xid' => 'AAIBBYNoEwAAACcKhAJkdQAAAAA=',
                'directoryServerTransactionId' => 'cd346fc0-d248-48f7-9b76-1f4741076fec',
                'threeDSServerTransactionId' => '3bf3718f-39d0-42eb-acda-ced2f80fc6a6',
                'specificationVersion' => '2.2.0',
                'acsTransactionId' => '27442f28-623b-4115-ad48-6ede081db03c',
        ]));

        $order->refresh();
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('data', $result);
    }
}
