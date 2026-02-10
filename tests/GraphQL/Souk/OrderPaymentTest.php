<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;

class OrderPaymentTest extends OrderBase
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

    public function testAddCashPaymentToOrder(): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        $response = $this->graphQL('
            mutation addPaymentToOrder($orderID: ID!, $input: PaymentInput!) {
                addPaymentToOrder(orderID: $orderID, input: $input) {
                    status
                    message
                    payment {
                        id
                        status
                    }
                    order {
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

        $response->assertSuccessful();
        $data = $response->json('data.addPaymentToOrder');
        $this->assertEquals('success', $data['status']);
        $this->assertEquals(PaymentStatusEnum::PAID->value, $data['payment']['status']);
    }

    public function testAddBankTransferPaymentToOrder(): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        $response = $this->graphQL('
            mutation addPaymentToOrder($orderID: ID!, $input: PaymentInput!) {
                addPaymentToOrder(orderID: $orderID, input: $input) {
                    status
                    message
                    payment {
                        id
                        status
                    }
                    order {
                        id
                    }
                }
            }
        ', [
            'orderID' => $order->id,
            'input' => [
                'payment_method' => 'BANK_TRANSFER',
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $response->assertSuccessful();
        $data = $response->json('data.addPaymentToOrder');
        $this->assertEquals('success', $data['status']);
        $this->assertEquals(PaymentStatusEnum::PENDING->value, $data['payment']['status']);
    }

    public function testAddCardPaymentToOrderWithPaymentMethod(): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        $paymentMethod = $this->addPaymentMethod($this->company, $this->getCardData());

        $response = $this->graphQL('
            mutation addPaymentToOrder($orderID: ID!, $input: PaymentInput!) {
                addPaymentToOrder(orderID: $orderID, input: $input) {
                    status
                    message
                    payment {
                        id
                        status
                    }
                    order {
                        id
                    }
                }
            }
        ', [
            'orderID' => $order->id,
            'input' => [
                'payment_methods_id' => $paymentMethod['id'],
                'payment_method' => 'CARD',
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $response->assertSuccessful();
        $data = $response->json('data.addPaymentToOrder');
        $this->assertEquals('success', $data['status']);
        $this->assertEquals(PaymentStatusEnum::PENDING->value, $data['payment']['status']);
    }

    public function testAddPaymentToOrderRequiresPaymentMethodIdForCard(): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        $response = $this->graphQL('
            mutation addPaymentToOrder($orderID: ID!, $input: PaymentInput!) {
                addPaymentToOrder(orderID: $orderID, input: $input) {
                    status
                    message
                }
            }
        ', [
            'orderID' => $order->id,
            'input' => [
                'payment_method' => 'CARD',
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $response->assertSuccessful();
        $data = $response->json('data.addPaymentToOrder');
        $this->assertEquals('error', $data['status']);
        $this->assertEquals('Payment method not found', $data['message']);
    }

    public function testAddPaymentBlockedWhenOrderIsPaid(): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        // Pay with cash first
        $this->graphQL('
            mutation addPaymentToOrder($orderID: ID!, $input: PaymentInput!) {
                addPaymentToOrder(orderID: $orderID, input: $input) {
                    status
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

        // Try to add another payment
        $response = $this->graphQL('
            mutation addPaymentToOrder($orderID: ID!, $input: PaymentInput!) {
                addPaymentToOrder(orderID: $orderID, input: $input) {
                    status
                    message
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

        $response->assertSuccessful();
        $data = $response->json('data.addPaymentToOrder');
        $this->assertEquals('error', $data['status']);
        $this->assertEquals('Order is already paid', $data['message']);
    }

    public function testAddPaymentBlockedWhenOrderHasAuthorizedPayment(): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        // Create an authorized payment directly in DB
        $order->payments()->create([
            'amount' => $order->getTotalAmount(),
            'payment_date' => date('Y-m-d'),
            'concept' => 'Test authorized payment',
            'users_id' => $this->user->getId(),
            'companies_id' => $order->companies_id,
            'currency' => $order->currency,
            'status' => PaymentStatusEnum::AUTHORIZED->value,
        ]);

        $response = $this->graphQL('
            mutation addPaymentToOrder($orderID: ID!, $input: PaymentInput!) {
                addPaymentToOrder(orderID: $orderID, input: $input) {
                    status
                    message
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

        $response->assertSuccessful();
        $data = $response->json('data.addPaymentToOrder');
        $this->assertEquals('error', $data['status']);
        $this->assertEquals('Order already has an authorized payment', $data['message']);
    }

    public function testAddPaymentBlockedWhenOrderHasProcessingPayment(): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        // Create a processing payment directly in DB
        $order->payments()->create([
            'amount' => $order->getTotalAmount(),
            'payment_date' => date('Y-m-d'),
            'concept' => 'Test processing payment',
            'users_id' => $this->user->getId(),
            'companies_id' => $order->companies_id,
            'currency' => $order->currency,
            'status' => PaymentStatusEnum::PROCESSING->value,
        ]);

        $response = $this->graphQL('
            mutation addPaymentToOrder($orderID: ID!, $input: PaymentInput!) {
                addPaymentToOrder(orderID: $orderID, input: $input) {
                    status
                    message
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

        $response->assertSuccessful();
        $data = $response->json('data.addPaymentToOrder');
        $this->assertEquals('error', $data['status']);
        $this->assertEquals('Order has a payment currently being processed', $data['message']);
    }

    public function testCashPaymentAutoTransitionsToPaid(): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        $response = $this->graphQL('
            mutation addPaymentToOrder($orderID: ID!, $input: PaymentInput!) {
                addPaymentToOrder(orderID: $orderID, input: $input) {
                    status
                    message
                    payment {
                        id
                        status
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

        $response->assertSuccessful();
        $data = $response->json('data.addPaymentToOrder');
        $this->assertEquals('success', $data['status']);
        $this->assertEquals(PaymentStatusEnum::PAID->value, $data['payment']['status']);

        // Verify the order is marked as paid
        $order->refresh();
        $this->assertTrue($order->isPaid());
    }

    public function testPendingPaymentsAreForceDeletedOnNewPayment(): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        $paymentMethod = $this->addPaymentMethod($this->company, $this->getCardData());

        // Add a card payment (stays pending)
        $this->graphQL('
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
                'payment_methods_id' => $paymentMethod['id'],
                'payment_method' => 'CARD',
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $pendingPaymentCount = $order->payments()->pending()->count();
        $this->assertEquals(1, $pendingPaymentCount);

        // Add another card payment - previous pending should be force deleted
        $this->graphQL('
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
                'payment_methods_id' => $paymentMethod['id'],
                'payment_method' => 'CARD',
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        // Should still only have 1 pending payment (old one force deleted)
        $pendingPaymentCount = $order->payments()->pending()->count();
        $this->assertEquals(1, $pendingPaymentCount);

        // Verify old payment is truly gone (force deleted, not soft deleted)
        $totalPayments = Payments::where('payable_id', $order->id)
            ->where('payable_type', get_class($order))
            ->count();
        $this->assertEquals(1, $totalPayments);
    }

    public function testPaymentsQuery(): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        // Create a payment
        $this->graphQL('
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

        $response = $this->graphQL('
            query payments {
                payments(first: 10) {
                    data {
                        id
                        uuid
                        amount
                        status
                        payment_method_type
                        created_at
                    }
                }
            }
        ', [], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $response->assertSuccessful();
        $payments = $response->json('data.payments.data');
        $this->assertNotEmpty($payments);
    }

    public function testPaymentsQueryFilterByStatus(): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        // Create a paid payment via cash
        $this->graphQL('
            mutation addPaymentToOrder($orderID: ID!, $input: PaymentInput!) {
                addPaymentToOrder(orderID: $orderID, input: $input) {
                    status
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

        $response = $this->graphQL('
            query payments($status: Mixed!) {
                payments(
                    first: 10,
                    where: { column: STATUS, operator: EQ, value: $status }
                ) {
                    data {
                        id
                        status
                    }
                }
            }
        ', [
            'status' => PaymentStatusEnum::PAID->value,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $response->assertSuccessful();
        $payments = $response->json('data.payments.data');
        $this->assertNotEmpty($payments);

        foreach ($payments as $payment) {
            $this->assertEquals(PaymentStatusEnum::PAID->value, $payment['status']);
        }
    }
}
