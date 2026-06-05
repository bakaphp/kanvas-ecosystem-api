<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

class OrderPaymentStatsProviderTest extends OrderBase
{
    protected string $variantId;

    private string $orderPaymentStatsQuery = '
        query OrderPaymentStats($input: OrderPaymentStatsInput) {
            orderPaymentStats(input: $input) {
                period { start end }
                ordersInPeriod {
                    count
                    totalAmount
                    byProvider { name count totalAmount }
                    data { date count totalAmount }
                }
                byPeriod { label total_transactions total_amount }
                periods { period_type count avg_count total amount_avg periods_in_range }
            }
        }
    ';

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

    public function testOrderPaymentStatsWithoutProviderFilterReturnsSuccessfully(): void
    {
        $response = $this->graphQL($this->orderPaymentStatsQuery, [
            'input' => [
                'paidStates' => ['paid'],
                'startDate' => '2026-01-01',
                'endDate' => '2026-03-31',
                'timezone' => 'UTC',
            ],
        ]);

        $response->assertSuccessful();

        $data = $response->json('data.orderPaymentStats');
        $this->assertArrayHasKey('ordersInPeriod', $data);
        $this->assertArrayHasKey('byPeriod', $data);
        $this->assertArrayHasKey('periods', $data);
    }

    public function testOrderPaymentStatsWithProviderCompanyIdFilter(): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        $order->providerCompanies()->attach($this->company->id);

        $response = $this->graphQL($this->orderPaymentStatsQuery, [
            'input' => [
                'paidStates' => ['paid'],
                'startDate' => '2026-01-01',
                'endDate' => '2026-03-31',
                'timezone' => 'UTC',
                'provider_company_id' => [$this->company->id],
            ],
        ]);

        $response->assertSuccessful();

        $data = $response->json('data.orderPaymentStats');
        $this->assertArrayHasKey('ordersInPeriod', $data);
    }

    public function testOrderPaymentStatsByPeriodIsFilteredByProviderCompanyId(): void
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

        $response = $this->graphQL($this->orderPaymentStatsQuery, [
            'input' => [
                'paidStates' => ['paid'],
                'startDate' => '2026-01-01',
                'endDate' => '2026-03-31',
                'timezone' => 'UTC',
                'provider_company_id' => [$this->company->id],
                'periodBreakdown' => 'MONTH',
            ],
        ]);

        $response->assertSuccessful();

        $data = $response->json('data.orderPaymentStats');
        $this->assertIsArray($data['byPeriod']);
    }

    public function testOrderPaymentStatsCapturesOrdersWithPaymentStatusPaidWithoutStatusTransition(): void
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
                    payment { id }
                }
            }
        ', [
            'orderID' => $order->id,
            'input' => ['payment_method' => 'CASH'],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $order->refresh();
        $this->assertEquals('paid', $order->payment_status);

        // Use a paidStates slug that this order has NOT transitioned to.
        // The order should still be captured via its paid payment record (payment_date).
        $response = $this->graphQL($this->orderPaymentStatsQuery, [
            'input' => [
                'paidStates' => ['non-existent-slug'],
                'startDate' => now()->subDay()->format('Y-m-d'),
                'endDate' => now()->addDay()->format('Y-m-d'),
                'timezone' => 'UTC',
            ],
        ]);

        $response->assertSuccessful();
        $this->assertGreaterThanOrEqual(1, $response->json('data.orderPaymentStats.ordersInPeriod.count'));
        $this->assertGreaterThan(0, $response->json('data.orderPaymentStats.ordersInPeriod.totalAmount'));
    }

    public function testOrderPaymentStatsFiltersByMetadataPath(): void
    {
        $tagValue = 'tag-' . uniqid();

        $tagged = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => ['paso_rapido_tag' => $tagValue]],
        );

        $untagged = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        foreach ([$tagged, $untagged] as $order) {
            $this->graphQL('
                mutation addPaymentToOrder($orderID: ID!, $input: PaymentInput!) {
                    addPaymentToOrder(orderID: $orderID, input: $input) {
                        status
                        payment { id }
                    }
                }
            ', [
                'orderID' => $order->id,
                'input' => ['payment_method' => 'CASH'],
            ], [], [
                'X-Kanvas-Location' => $this->company->branch->uuid,
            ]);
        }

        $response = $this->graphQL($this->orderPaymentStatsQuery, [
            'input' => [
                'paidStates' => ['paid'],
                'startDate' => now()->subDay()->format('Y-m-d'),
                'endDate' => now()->addDay()->format('Y-m-d'),
                'timezone' => 'UTC',
                'metadata' => [
                    'path' => 'data.paso_rapido_tag',
                    'value' => $tagValue,
                    'operator' => 'EQ',
                ],
            ],
        ]);

        $response->assertSuccessful();

        $body = $response->json() ?? [];
        $this->assertIsArray($body, 'GraphQL response body was not JSON-parseable');

        if (isset($body['errors'])) {
            $this->fail('orderPaymentStats returned GraphQL errors: ' . json_encode($body['errors']));
        }

        $payload = $body['data']['orderPaymentStats'] ?? null;
        $this->assertNotNull($payload, 'data.orderPaymentStats is null. Full body: ' . json_encode($body));

        $ordersInPeriod = $payload['ordersInPeriod'] ?? null;
        $this->assertIsArray($ordersInPeriod, 'ordersInPeriod missing. Payload: ' . json_encode($payload));
        $this->assertEquals(1, $ordersInPeriod['count']);
        $this->assertGreaterThan(0, $ordersInPeriod['totalAmount']);

        $dailyTotals = array_sum(array_column($ordersInPeriod['data'] ?? [], 'totalAmount'));
        $this->assertEqualsWithDelta($ordersInPeriod['totalAmount'], $dailyTotals, 0.01);
    }

    public function testOrderPaymentStatsFiltersByOrderNumber(): void
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
                    payment { id }
                }
            }
        ', [
            'orderID' => $order->id,
            'input' => ['payment_method' => 'CASH'],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $order->refresh();

        $response = $this->graphQL($this->orderPaymentStatsQuery, [
            'input' => [
                'paidStates' => ['paid'],
                'startDate' => now()->subDay()->format('Y-m-d'),
                'endDate' => now()->addDay()->format('Y-m-d'),
                'timezone' => 'UTC',
                'orderNumber' => (string) $order->order_number,
            ],
        ]);

        $response->assertSuccessful();

        $body = $response->json() ?? [];
        $this->assertIsArray($body, 'GraphQL response body was not JSON-parseable');

        if (isset($body['errors'])) {
            $this->fail('orderPaymentStats returned GraphQL errors: ' . json_encode($body['errors']));
        }

        $payload = $body['data']['orderPaymentStats'] ?? null;
        $this->assertNotNull($payload, 'data.orderPaymentStats is null. Full body: ' . json_encode($body));

        $this->assertGreaterThanOrEqual(1, $payload['ordersInPeriod']['count'] ?? 0);
    }
}
