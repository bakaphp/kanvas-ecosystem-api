<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\PasoRapido;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\EchoPay\Enums\CustomFieldEnum as EnumsCustomFieldEnum;
use Kanvas\Connectors\PasoRapido\Actions\CreatePasoRapidoOrderAction;
use Kanvas\Connectors\PasoRapido\Enums\ConfigurationEnum;
use Kanvas\Connectors\PasoRapido\Enums\CustomFieldEnum;
use Kanvas\Connectors\PasoRapido\Handlers\PasoRapidoHandler;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

final class PasoRapidoOrderTest extends TestCase
{
    use HasIntegrationCompany;
    use InventoryCases;

    public function testCreatePasoRapidoOrder(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $region = Regions::getDefault($company ?? $company, $app);

        $app->set(ConfigurationEnum::CLIENT_ID->value, env('TEST_PASO_RAPIDO_CLIENT_ID'));
        $app->set(ConfigurationEnum::SECRET->value, env('TEST_PASO_RAPIDO_SECRET'));

        $this->setIntegration(
            $app,
            IntegrationsEnum::PASO_RAPIDO,
            PasoRapidoHandler::class,
            $company,
            $user
        );

        $warehouseResponse = $this->createWarehouses((string) $region->getId())->json()['data']['createWarehouse'];
        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'slots',
                'value' => 100
            ]
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

        $transactionId = "7478925724996114" . rand(100000, 999999);

        $data = [
            'email' => fake()->email(),
            'region_id' => $region->getId(),
            'metadata' => [
                'data' => [
                    'paso_rapido_tag' => "317169",
                    'payment_methods_id' => "91",
                    'payment_date' => now()->toDateTimeString(),
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
                    'price' => 100,
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

        $order->set(EnumsCustomFieldEnum::ECHO_PAY_PAYMENT_INTENT_ID->value, 'intentId:' . $transactionId);
        $order->set(CustomFieldEnum::PASO_RAPIDO_DNI->value, "1234567890");

        $createPasoRapidoOrderAction = new CreatePasoRapidoOrderAction($app, $order);
        $result = $createPasoRapidoOrderAction->execute();

        $order->refresh();
        $this->assertArrayHasKey('order', $result['data']);
        $this->assertArrayHasKey('tag', $result['data']);
        $this->assertNotNull($order->get(CustomFieldEnum::PASO_RAPIDO_PAYMENT_STATUS->value));
        $this->assertNotNull($order->get(CustomFieldEnum::PASO_RAPIDO_PAYMENT_RESPONSE->value));
    }
}
