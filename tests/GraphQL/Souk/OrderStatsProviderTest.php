<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

class OrderStatsProviderTest extends OrderBase
{
    protected string $variantId;

    private string $orderStatsQuery = '
        query OrderStats($input: OrderStatsInput) {
            orderStats(input: $input) {
                period { start end }
                ordersInPeriod {
                    orderAvg
                    data { date count states }
                }
                currentCount
                dailyTurnover {
                    totalEntries
                    totalExits
                    data { date entries exits }
                }
                averageRotation { averageTime }
                byProvider { name count totalAmount }
                groupBy
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

    public function testOrderStatsReturnsEmptyByProviderWhenProvidersNotSpecified(): void
    {
        $response = $this->graphQL($this->orderStatsQuery, [
            'input' => [
                'initialStates' => ['processing'],
                'finalStates' => ['completed'],
                'currentCountStates' => ['processing'],
                'startDate' => '2026-01-01',
                'endDate' => '2026-03-31',
                'timezone' => 'UTC',
            ],
        ]);

        $response->assertSuccessful();

        $data = $response->json('data.orderStats');
        $this->assertEquals([], $data['byProvider']);
    }

    public function testOrderStatsWithProviderCompanyIdFilter(): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        $order->providerCompanies()->attach($this->company->id);

        $response = $this->graphQL($this->orderStatsQuery, [
            'input' => [
                'initialStates' => ['processing'],
                'finalStates' => ['completed'],
                'currentCountStates' => ['processing'],
                'startDate' => '2026-01-01',
                'endDate' => '2026-03-31',
                'timezone' => 'UTC',
                'provider_company_id' => [$this->company->id],
            ],
        ]);

        $response->assertSuccessful();

        $data = $response->json('data.orderStats');
        $this->assertArrayHasKey('byProvider', $data);
        $this->assertIsArray($data['byProvider']);
    }

    public function testOrderStatsWithByProviderBreakdown(): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        $order->providerCompanies()->attach($this->company->id);

        $response = $this->graphQL($this->orderStatsQuery, [
            'input' => [
                'initialStates' => ['processing'],
                'finalStates' => ['completed'],
                'currentCountStates' => ['processing'],
                'startDate' => '2026-01-01',
                'endDate' => '2026-03-31',
                'timezone' => 'UTC',
                'providers' => [
                    ['name' => 'TestProvider', 'emailPattern' => '%@test.com'],
                ],
            ],
        ]);

        $response->assertSuccessful();

        $data = $response->json('data.orderStats');
        $this->assertIsArray($data['byProvider']);
    }
}
