<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Handlers\MovipassHandler;
use Kanvas\Connectors\Movipass\Workflows\Activities\ExtendReservationActivity;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\GraphQL\Souk\Traits\PaymentCases;
use Tests\TestCase;

final class ExtendReservationActivityTest extends TestCase
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

        $this->setIntegration(
            $app,
            IntegrationsEnum::MOVIPASS,
            MovipassHandler::class,
            $company,
            $user
        );

        $this->setAllowNoPaymentStatus(true, $app);

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

        $endDate = now('America/New_York')->addMinutes(30);

        $data = [
            'email' => fake()->email(),
            'region_id' => $region->getId(),
            'metadata' => [
                'data' => [
                    'start_at' => now('America/New_York')->toDateTimeString(),
                    'end_at' => $endDate->toDateTimeString(),
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

        $mainOrderData = $response->json()['data']['createDraftOrder'];

        $extendedEndAt = $endDate->addMinutes(31)->toDateTimeString();
        $extendReservationResponse = $this->graphQL('
            mutation extendOrder($id: ID!, $input: ExtendOrderInput!) {
                extendOrder(id: $id, input: $input) {
                    order {
                        id
                    }
                }
            }
        ', [
            'id' => $mainOrderData['id'],
            'input' => [
                'customer' => [
                    'email' => fake()->email(),
                ],
                'order_type' => "paso_rapido",
                'items' => [
                    [
                        'quantity' => 1,
                        'variant_id' => $variantResponse['id'],
                        'price' => 100,
                    ],
                ],
                'metadata' => [
                    'data' => [
                        'start_at' => $endDate->addMinutes(1)->toDateTimeString(),
                        'end_at' => $extendedEndAt
                    ],
                ],
                'reference' => "recarga_paso_rapido",
            ],
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
            'X-Kanvas-App' => $app->key,
        ]);

        $mainOrder = Order::fromApp($app)->find($mainOrderData['id']);
        $orderExtended = Order::fromApp($app)->find($extendReservationResponse->json()['data']['extendOrder']['order']['id']);

        $activity = new ExtendReservationActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $orderExtended->status = 'completed';
        $orderExtended->save();

        $result = $activity->execute($orderExtended, $app, []);
        $mainOrder->refresh();
        $this->assertEquals($result['status'], 'success');
        $this->assertEquals($result['message'], 'Reservation extended');
        $this->assertEquals($mainOrder->fresh()->metadata['data']['end_at'], $extendedEndAt);
        $this->assertEquals($orderExtended->status, 'completed');
    }
}
