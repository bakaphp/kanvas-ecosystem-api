<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Handlers\MovipassHandler;
use Kanvas\Connectors\Movipass\Workflows\Activities\SyncMovipassImpoundActivity;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Enums\OrderFulfillmentStatusEnum;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\GraphQL\Souk\Traits\PaymentCases;
use Tests\TestCase;

final class SyncMovipassImpoundActivityTest extends TestCase
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
                'value' => 100,
            ],
        ])->json()['data']['createProduct'];

        $warehouseData = [
            'id' => $warehouseResponse['id'],
        ];

        $product = Products::fromApp($app)->find($productResponse['id']);

        $channelResponse = $this->createChannel()->json()['data']['createChannel'];

        $this->addVariantToChannel(
            variantId: (string) $product->variants->first()->id,
            channelId: $channelResponse['id'],
            warehouseData: $warehouseData
        );

        $this->addVariantToWarehouse(
            variantId: (string) $product->variants->first()->id,
            warehouseId: $warehouseResponse['id'],
            amount: 100
        );

        $data = [
            'cartId' => 0,
            'customer' => [
                'email' => fake()->email(),
            ],
            'order_type' => OrderTypeEnum::IMPOUND_LOT->value,
            'metadata' => [
                'data' => [],
            ],
            'items' => [
                [
                    'variant_id' => (string) $product->variants->first()->id,
                    'quantity' => 1,
                    'price' => 100,
                ],
            ],
            'reference' => 'Charge for impound lot',
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

        $activity = new SyncMovipassImpoundActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $activity->execute($order, $app, [
            'currentEventTypeName' => WorkflowEnum::CREATED->value,
        ]);

        $order->refresh();
        $this->assertEquals($result['status'], 'success');
        $this->assertEquals($result['message'], 'Order synced correctly');
        $this->assertEquals($order->reference, 'Charge for impound lot - #' . $order->order_number);
        $this->assertEquals($order->status, OrderStatusEnum::PENDING->value);
    }

    public function testOrderPaidWorkflow(): void
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
                'value' => 100,
            ],
        ])->json()['data']['createProduct'];

        $warehouseData = [
            'id' => $warehouseResponse['id'],
        ];

        $product = Products::fromApp($app)->find($productResponse['id']);

        $channelResponse = $this->createChannel()->json()['data']['createChannel'];

        $this->addVariantToChannel(
            variantId: (string) $product->variants->first()->id,
            channelId: $channelResponse['id'],
            warehouseData: $warehouseData
        );

        $this->addVariantToWarehouse(
            variantId: (string) $product->variants->first()->id,
            warehouseId: $warehouseResponse['id'],
            amount: 100
        );

        $data = [
            'cartId' => 0,
            'customer' => [
                'email' => fake()->email(),
            ],
            'order_type' => OrderTypeEnum::IMPOUND_LOT->value,
            'metadata' => [
                'data' => [],
            ],
            'items' => [
                [
                    'variant_id' => (string) $product->variants->first()->id,
                    'quantity' => 1,
                    'price' => 100,
                ],
            ],
            'reference' => 'Charge for impound lot',
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

        $activity = new SyncMovipassImpoundActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $activity->execute($order, $app, [
            'currentEventTypeName' => WorkflowEnum::STATUS_TRANSITION->value,
            'to_status' => MovipassOrderStatusEnum::PAID->value,
        ]);

        $order->refresh();
        $this->assertEquals($result['status'], 'success');
        $this->assertEquals($result['message'], 'Order synced correctly');
        $this->assertEquals($order->fulfillment_status, OrderFulfillmentStatusEnum::COMPLETED->value);
    }

    public function testOrderReleaseWorkflow(): void
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
                'value' => 100,
            ],
        ])->json()['data']['createProduct'];

        $warehouseData = [
            'id' => $warehouseResponse['id'],
        ];

        $product = Products::fromApp($app)->find($productResponse['id']);

        $channelResponse = $this->createChannel()->json()['data']['createChannel'];

        $this->addVariantToChannel(
            variantId: (string) $product->variants->first()->id,
            channelId: $channelResponse['id'],
            warehouseData: $warehouseData
        );

        $this->addVariantToWarehouse(
            variantId: (string) $product->variants->first()->id,
            warehouseId: $warehouseResponse['id'],
            amount: 100
        );

        $data = [
            'cartId' => 0,
            'customer' => [
                'email' => fake()->email(),
            ],
            'order_type' => OrderTypeEnum::IMPOUND_LOT->value,
            'metadata' => [
                "data" => [
                    "start_at" => "2025-09-10 17:17",
                    "vehicleBrand" => "Chevrolet",
                    "vehicleColor" => "blanco",
                    "vehiclePlate" => "T000001",
                    "images" => [],
                    "carDeposit" => [
                        "id" => "259177",
                        "name" => "Centro de Retención",
                        "description" => "Avenida 27 de Febrero",
                    ],
                    "terms_and_conditions" => true,
                    "parking_spot" => "8",
                    "proof_images" => [],
                    "delivery_time" => "2025-09-10 17:25",
                    "observations" => null,
                    "payment_images" => [],
                ]
            ],
            'items' => [
                [
                    'variant_id' => (string) $product->variants->first()->id,
                    'quantity' => 1,
                    'price' => 100,
                ],
            ],
            'reference' => 'Charge for impound lot',
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

        $activity = new SyncMovipassImpoundActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $activity->execute($order, $app, [
            'currentEventTypeName' => WorkflowEnum::STATUS_TRANSITION->value,
            'to_status' => MovipassOrderStatusEnum::RELEASED->value,
        ]);

        $order->refresh();
        print_r($result);
        $this->assertEquals($result['status'], 'processing');
        $this->assertEquals($result['message'], 'PDF generation started. You will receive an email with the download link when ready.');
    }
}
