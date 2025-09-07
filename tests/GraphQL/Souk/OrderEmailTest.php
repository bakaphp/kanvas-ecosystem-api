<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Apps\Actions\SyncEmailTemplateAction;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\Order;
use Tests\Connectors\Traits\HasStripeConfiguration;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\GraphQL\Souk\Traits\PaymentCases;

class OrderEmailTest extends OrderBase
{
    use InventoryCases;
    use PaymentCases;
    use HasStripeConfiguration;
    protected $orderTypeName = 'reservation';

    public function testSendOrderEmail()
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $syncEmailTemplate = new SyncEmailTemplateAction($app, $user);
        $syncEmailTemplate->execute();

        // Create a product
        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'slots',
                'value' => 100,
            ],
        ])->json()['data']['createProduct'];

        // Create a variant
        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
            ],
            attributes: [
                [
                    'name' => 'timezone',
                    'value' => 'America/New_York',
                ],
            ]
        )->json()['data']['createVariant'];

        // Add variant to channel
        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
            ]
        );

        // Add variant to warehouse
        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        // Create an order
        $order = $this->createOrderFromCart(
            variantId: $variantResponse['id'],
            quantity: 1,
            metadata: [
                'data' => [],
            ],
            orderType: $this->orderTypeName
        );

        // Test sending order email
        $response = $this->graphQL('
            mutation SendOrderEmail($order_id: ID!) {
                sendOrderEmail(
                    order_id: $order_id
                )
            }
        ', [
            'order_id' => $order->id,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-Key' => $app->keys()->first()->client_secret_id,
        ]);

        $this->assertArrayHasKey('sendOrderEmail', $response->json('data'));
        $this->assertTrue($response->json('data.sendOrderEmail'));
    }
}
