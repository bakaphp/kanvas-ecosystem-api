<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Handlers\MovipassHandler;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;

/**
 * Requires HasIntegrationCompany, InventoryCases and PaymentCases on the consuming test case.
 */
trait CreatesMovipassParkingOrder
{
    protected function createMovipassOrder(
        Apps $app,
        array $metadataData = [],
        array $productAttributes = []
    ): Order {
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $region = Regions::getDefault($company, $app);

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
            ...$productAttributes,
        ])->json()['data']['createProduct'];

        $product = Products::fromApp($app)->find($productResponse['id']);

        $channelResponse = $this->createChannel()->json()['data']['createChannel'];

        $this->addVariantToChannel(
            variantId: (string) $product->variants->first()->id,
            channelId: $channelResponse['id'],
            warehouseData: ['id' => $warehouseResponse['id']]
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
            'order_type' => OrderTypeEnum::MOVIPASS->value,
            'metadata' => [
                'data' => array_merge([
                    'vehiclePlate' => 'T000001',
                    'vehicleBrand' => 'Toyota',
                    'vehicleColor' => 'red',
                    'is_manual' => false,
                ], $metadataData),
            ],
            'items' => [
                [
                    'variant_id' => (string) $product->variants->first()->id,
                    'quantity' => 1,
                    'price' => 100,
                ],
            ],
            'reference' => 'Movipass parking test',
        ];

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

        $orderId = $response->json('data.createOrderFromCart.order.id');

        return Order::fromApp($app)->find($orderId);
    }
}
