<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Souk\Payments\Actions\LogPaymentEventAction;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;

class OrderPaymentLogTest extends OrderBase
{
    protected string $variantId;

    public function setUp(): void
    {
        parent::setUp();

        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'slots',
                'value' => 100,
            ],
        ])->json()['data']['createProduct'];

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

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
            ]
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $this->variantId = $variantResponse['id'];
    }

    public function testOrderPaymentLogsRelationship(): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        $paymentResponse = $this->graphQL('
            mutation addPaymentToOrder($orderID: ID!, $input: PaymentInput!) {
                addPaymentToOrder(orderID: $orderID, input: $input) {
                    status
                    payment {
                        id
                    }
                }
            }
        ', [
            'orderID' => $order->id,
            'input' => [
                'payment_method' => 'CASH',
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);
        $paymentResponse->assertSuccessful();

        $payment = \Kanvas\Souk\Payments\Models\Payments::find(
            $paymentResponse->json('data.addPaymentToOrder.payment.id')
        );
        $payment->addLog('test_event', ['test_key' => 'test_value']);

        $response = $this->graphQL('
            query orders($where: QueryOrdersWhereWhereConditions!) {
                orders(where: $where) {
                    data {
                        id
                        payment_logs {
                            id
                            status
                            metadata
                            user {
                                id
                            }
                            company {
                                id
                            }
                            created_at
                        }
                    }
                }
            }
        ', [
            'where' => [
                'column' => 'ID',
                'operator' => 'EQ',
                'value' => $order->id,
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $response->assertSuccessful();
        $orderData = $response->json('data.orders.data.0');
        $this->assertNotNull($orderData);
        $this->assertArrayHasKey('payment_logs', $orderData);
        $this->assertNotEmpty($orderData['payment_logs']);

        $log = $orderData['payment_logs'][0];
        $this->assertNotNull($log['id']);
        $this->assertEquals('test_event', $log['status']);
        $this->assertNotNull($log['metadata']);
        $this->assertNotNull($log['user']['id']);
        $this->assertNotNull($log['company']['id']);
        $this->assertNotNull($log['created_at']);
    }

    public function testPaymentLogsNestedThroughPayment(): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        $paymentResponse = $this->graphQL('
            mutation addPaymentToOrder($orderID: ID!, $input: PaymentInput!) {
                addPaymentToOrder(orderID: $orderID, input: $input) {
                    status
                    payment {
                        id
                    }
                }
            }
        ', [
            'orderID' => $order->id,
            'input' => [
                'payment_method' => 'CASH',
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);
        $paymentResponse->assertSuccessful();

        $payment = \Kanvas\Souk\Payments\Models\Payments::find(
            $paymentResponse->json('data.addPaymentToOrder.payment.id')
        );
        $payment->addLog('payment_completed', ['method' => 'cash']);

        $response = $this->graphQL('
            query orders($where: QueryOrdersWhereWhereConditions!) {
                orders(where: $where) {
                    data {
                        id
                        payments {
                            id
                            status
                            amount
                            payment_logs {
                                id
                                status
                                metadata
                                created_at
                            }
                        }
                    }
                }
            }
        ', [
            'where' => [
                'column' => 'ID',
                'operator' => 'EQ',
                'value' => $order->id,
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $response->assertSuccessful();
        $orderData = $response->json('data.orders.data.0');
        $this->assertNotNull($orderData);
        $this->assertNotEmpty($orderData['payments']);

        $payment = $orderData['payments'][0];
        $this->assertEquals(PaymentStatusEnum::PAID->value, $payment['status']);
        $this->assertArrayHasKey('payment_logs', $payment);
    }
}
