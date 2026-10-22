<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

final class OrderNumberSerializationTest extends OrderBase
{
    public function testOrderNumberAboveThirtyTwoBitRangeSerializes(): void
    {
        $productResponse = $this->createProduct()->json()['data']['createProduct'];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
        )->json()['data']['createVariant'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: 10
        );

        $order = $this->createOrderFromCart(metadata: ['data' => []], variantId: $variantResponse['id']);

        $order->order_number = 6300236457;
        $order->saveOrFail();

        $response = $this->graphQL('
            query ($id: Mixed) {
                orders(where: { column: ID, operator: EQ, value: $id }) {
                    data {
                        id
                        order_number
                    }
                }
            }
        ', ['id' => $order->getId()]);

        $response->assertSuccessful();
        $this->assertArrayNotHasKey('errors', $response->json());
        $this->assertSame('6300236457', $response->json('data.orders.data.0.order_number'));
    }
}
