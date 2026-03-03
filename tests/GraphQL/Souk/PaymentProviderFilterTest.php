<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

class PaymentProviderFilterTest extends OrderBase
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

    public function testPaymentsQueryWithHasProviderFilter(): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        $order->providerCompanies()->attach($this->company->id);

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
            query($companyId: Mixed) {
                payments(
                    first: 10,
                    hasProvider: { column: COMPANY_ID, value: $companyId }
                ) {
                    data {
                        id
                        status
                    }
                }
            }
        ', [
            'companyId' => $this->company->id,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $response->assertSuccessful();
        $this->assertNotEmpty($response->json('data.payments.data'));
    }

    public function testPaymentsQueryHasProviderWithNonExistentIdReturnsEmpty(): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

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
            query($companyId: Mixed) {
                payments(
                    first: 10,
                    hasProvider: { column: COMPANY_ID, value: $companyId }
                ) {
                    data {
                        id
                        status
                    }
                }
            }
        ', [
            'companyId' => 999999,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $response->assertSuccessful();
        $this->assertEmpty($response->json('data.payments.data'));
    }

    public function testPaymentsQueryWithoutHasProviderReturnsAll(): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

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
            query {
                payments(first: 10) {
                    data {
                        id
                        status
                    }
                }
            }
        ', [], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $response->assertSuccessful();
        $this->assertNotEmpty($response->json('data.payments.data'));
    }

    public function testProviderPaymentsQuery(): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        $order->providerCompanies()->attach($this->company->id);

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
            query($companyId: ID!) {
                providerPayments(provider_company_id: $companyId, first: 10) {
                    data {
                        id
                        status
                    }
                }
            }
        ', [
            'companyId' => $this->company->id,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $response->assertSuccessful();
        $this->assertNotEmpty($response->json('data.providerPayments.data'));
    }

    public function testProviderOrdersQuery(): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        $order->providerCompanies()->attach($this->company->id);

        $response = $this->graphQL('
            query($companyId: ID!) {
                providerOrders(provider_company_id: $companyId, first: 10) {
                    data {
                        id
                    }
                }
            }
        ', [
            'companyId' => $this->company->id,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $response->assertSuccessful();
        $this->assertNotEmpty($response->json('data.providerOrders.data'));
    }
}
