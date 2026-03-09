<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Souk\Orders\Enums\DateGroupByEnum;
use Kanvas\Souk\Orders\Helpers\DateGroupingHelper;

class OrderStatsGroupingTest extends OrderBase
{
    private string $orderStatsQuery = '
        query OrderStats($input: OrderStatsInput) {
            orderStats(input: $input) {
                period {
                    start
                    end
                }
                ordersInPeriod {
                    orderAvg
                    maxOrdersDate {
                        date
                        count
                    }
                    minOrdersDate {
                        date
                        count
                    }
                    data {
                        date
                        states
                        count
                    }
                }
                currentCount
                dailyTurnover {
                    totalEntries
                    totalExits
                    exitAvg
                    entryAvg
                    maxExitDate {
                        date
                        count
                    }
                    maxEntryDate {
                        date
                        count
                    }
                    data {
                        date
                        entries
                        exits
                    }
                }
                averageRotation {
                    averageTime
                }
                orderRotationAvg
                groupBy
            }
        }
    ';

    public function testOrderStatsBackwardCompatibility(): void
    {
        $response = $this->graphQL($this->orderStatsQuery, [
            'input' => [
                'initialStates' => ['paid'],
                'finalStates' => ['completed'],
                'currentCountStates' => ['paid'],
                'startDate' => '2026-02-01',
                'endDate' => '2026-02-03',
                'timezone' => 'UTC',
            ],
        ]);

        $response->assertSuccessful();

        $data = $response->json('data.orderStats');
        $this->assertArrayHasKey('period', $data);
        $this->assertArrayHasKey('ordersInPeriod', $data);
        $this->assertArrayHasKey('currentCount', $data);
        $this->assertArrayHasKey('dailyTurnover', $data);
        $this->assertArrayHasKey('averageRotation', $data);
        $this->assertArrayHasKey('orderRotationAvg', $data);
        $this->assertArrayHasKey('groupBy', $data);
        $this->assertEquals('day', $data['groupBy']);
    }

    public function testOrderStatsGroupByDay(): void
    {
        $response = $this->graphQL($this->orderStatsQuery, [
            'input' => [
                'initialStates' => ['paid'],
                'finalStates' => ['completed'],
                'currentCountStates' => ['paid'],
                'startDate' => '2026-02-01',
                'endDate' => '2026-02-03',
                'timezone' => 'UTC',
                'groupBy' => 'DAY',
            ],
        ]);

        $response->assertSuccessful();

        $data = $response->json('data.orderStats');
        $this->assertEquals('day', $data['groupBy']);
        $this->assertCount(3, $data['ordersInPeriod']['data']);
    }

    public function testOrderStatsGroupByWeek(): void
    {
        $response = $this->graphQL($this->orderStatsQuery, [
            'input' => [
                'initialStates' => ['paid'],
                'finalStates' => ['completed'],
                'currentCountStates' => ['paid'],
                'startDate' => '2026-02-01',
                'endDate' => '2026-02-14',
                'timezone' => 'UTC',
                'groupBy' => 'WEEK',
            ],
        ]);

        $response->assertSuccessful();

        $data = $response->json('data.orderStats');
        $this->assertEquals('week', $data['groupBy']);
        $this->assertNotEmpty($data['ordersInPeriod']);
    }

    public function testOrderStatsGroupByMonth(): void
    {
        $response = $this->graphQL($this->orderStatsQuery, [
            'input' => [
                'initialStates' => ['paid'],
                'finalStates' => ['completed'],
                'currentCountStates' => ['paid'],
                'startDate' => '2026-01-01',
                'endDate' => '2026-02-28',
                'timezone' => 'UTC',
                'groupBy' => 'MONTH',
            ],
        ]);

        $response->assertSuccessful();

        $data = $response->json('data.orderStats');
        $this->assertEquals('month', $data['groupBy']);
        $this->assertNotEmpty($data['ordersInPeriod']);
    }

    public function testDateGroupingHelperGroupByWeek(): void
    {
        $dailyData = [
            'orderAvg' => 5,
            'maxOrdersDate' => ['date' => '2026-02-03', 'count' => 10],
            'minOrdersDate' => ['date' => '2026-02-01', 'count' => 2],
            'data' => [
                ['date' => '2026-02-02', 'count' => 3, 'states' => [['state' => 'paid', 'count' => 3]]],
                ['date' => '2026-02-03', 'count' => 10, 'states' => [['state' => 'paid', 'count' => 7], ['state' => 'active', 'count' => 3]]],
                ['date' => '2026-02-04', 'count' => 5, 'states' => [['state' => 'paid', 'count' => 5]]],
                ['date' => '2026-02-09', 'count' => 8, 'states' => [['state' => 'paid', 'count' => 4], ['state' => 'active', 'count' => 4]]],
                ['date' => '2026-02-10', 'count' => 2, 'states' => [['state' => 'paid', 'count' => 2]]],
            ],
        ];

        $result = DateGroupingHelper::groupOrdersInPeriod($dailyData, DateGroupByEnum::WEEK, 'UTC');

        // 2026-02-02 (Mon) and 2026-02-03 (Tue) are in week starting 2026-02-02
        // 2026-02-04 (Wed) is also in week starting 2026-02-02
        // 2026-02-09 (Mon) and 2026-02-10 (Tue) are in week starting 2026-02-09
        $this->assertCount(2, $result['data']);
        $this->assertEquals('2026-02-02', $result['data'][0]['date']);
        $this->assertEquals('2026-02-09', $result['data'][1]['date']);

        // Week 1: 3 + 10 + 5 = 18
        $this->assertEquals(18, $result['data'][0]['count']);
        // Week 2: 8 + 2 = 10
        $this->assertEquals(10, $result['data'][1]['count']);

        // Max should be week 1 with 18
        $this->assertEquals(18, $result['maxOrdersDate']['count']);
        // Min should be week 2 with 10
        $this->assertEquals(10, $result['minOrdersDate']['count']);

        // Average: (18 + 10) / 2 = 14
        $this->assertEquals(14, $result['orderAvg']);
    }

    public function testDateGroupingHelperGroupByMonth(): void
    {
        $dailyData = [
            'orderAvg' => 5,
            'maxOrdersDate' => ['date' => '2026-01-15', 'count' => 10],
            'minOrdersDate' => ['date' => '2026-02-01', 'count' => 2],
            'data' => [
                ['date' => '2026-01-15', 'count' => 10, 'states' => [['state' => 'paid', 'count' => 10]]],
                ['date' => '2026-01-20', 'count' => 6, 'states' => [['state' => 'paid', 'count' => 6]]],
                ['date' => '2026-02-01', 'count' => 2, 'states' => [['state' => 'paid', 'count' => 2]]],
                ['date' => '2026-02-10', 'count' => 4, 'states' => [['state' => 'paid', 'count' => 4]]],
            ],
        ];

        $result = DateGroupingHelper::groupOrdersInPeriod($dailyData, DateGroupByEnum::MONTH, 'UTC');

        $this->assertCount(2, $result['data']);
        $this->assertEquals('2026-01-01', $result['data'][0]['date']);
        $this->assertEquals('2026-02-01', $result['data'][1]['date']);

        // January: 10 + 6 = 16
        $this->assertEquals(16, $result['data'][0]['count']);
        // February: 2 + 4 = 6
        $this->assertEquals(6, $result['data'][1]['count']);
    }

    public function testDateGroupingHelperTurnoverGroupByWeek(): void
    {
        $turnoverData = [
            'totalEntries' => 20,
            'totalExits' => 15,
            'exitAvg' => 5,
            'entryAvg' => 6.67,
            'exitPercentage' => 75,
            'maxExitDate' => ['date' => '2026-02-03', 'count' => 8],
            'maxEntryDate' => ['date' => '2026-02-03', 'count' => 10],
            'data' => [
                ['date' => '2026-02-02', 'entries' => 5, 'exits' => 3],
                ['date' => '2026-02-03', 'entries' => 10, 'exits' => 8],
                ['date' => '2026-02-09', 'entries' => 5, 'exits' => 4],
            ],
        ];

        $result = DateGroupingHelper::groupTurnoverData($turnoverData, DateGroupByEnum::WEEK, 'UTC');

        $this->assertCount(2, $result['data']);

        // Week 1: entries 5+10=15, exits 3+8=11
        $this->assertEquals(15, $result['data'][0]['entries']);
        $this->assertEquals(11, $result['data'][0]['exits']);

        // Week 2: entries 5, exits 4
        $this->assertEquals(5, $result['data'][1]['entries']);
        $this->assertEquals(4, $result['data'][1]['exits']);

        $this->assertEquals(20, $result['totalEntries']);
        $this->assertEquals(15, $result['totalExits']);
    }

    public function testDateGroupingHelperStateMerging(): void
    {
        $dailyData = [
            'orderAvg' => 5,
            'maxOrdersDate' => ['date' => '2026-02-02', 'count' => 10],
            'minOrdersDate' => ['date' => '2026-02-03', 'count' => 5],
            'data' => [
                ['date' => '2026-02-02', 'count' => 10, 'states' => [
                    ['state' => 'paid', 'count' => 6],
                    ['state' => 'active', 'count' => 4],
                ]],
                ['date' => '2026-02-03', 'count' => 5, 'states' => [
                    ['state' => 'paid', 'count' => 2],
                    ['state' => 'active', 'count' => 3],
                ]],
            ],
        ];

        $result = DateGroupingHelper::groupOrdersInPeriod($dailyData, DateGroupByEnum::WEEK, 'UTC');

        // Both dates are in the same week (2026-02-02 is Monday)
        $this->assertCount(1, $result['data']);
        $this->assertEquals(15, $result['data'][0]['count']);

        // States should be merged: paid=6+2=8, active=4+3=7
        $states = collect($result['data'][0]['states'])->keyBy('state');
        $this->assertEquals(8, $states['paid']['count']);
        $this->assertEquals(7, $states['active']['count']);
    }

    public function testOrderStatsFilterByProductId(): void
    {
        $response = $this->graphQL($this->orderStatsQuery, [
            'input' => [
                'initialStates' => ['paid'],
                'finalStates' => ['completed'],
                'currentCountStates' => ['paid'],
                'productId' => 1,
                'startDate' => '2026-02-01',
                'endDate' => '2026-02-03',
                'timezone' => 'UTC',
            ],
        ]);

        $response->assertSuccessful();

        $data = $response->json('data.orderStats');
        $this->assertArrayHasKey('period', $data);
        $this->assertArrayHasKey('ordersInPeriod', $data);
        $this->assertArrayHasKey('currentCount', $data);
        $this->assertArrayHasKey('dailyTurnover', $data);
        $this->assertArrayHasKey('averageRotation', $data);
    }

    public function testOrderPaymentStatsFilterByProductId(): void
    {
        $response = $this->graphQL('
            query OrderPaymentStats($input: OrderPaymentStatsInput) {
                orderPaymentStats(input: $input) {
                    period {
                        start
                        end
                    }
                    ordersInPeriod {
                        orderAvg
                        count
                        totalAmount
                        byServices {
                            variantId
                            productName
                            variantName
                            serviceName
                            orderCount
                            totalQuantity
                            totalAmount
                        }
                        data {
                            date
                            count
                            states
                        }
                    }
                    currentCount
                }
            }
        ', [
            'input' => [
                'paidStates' => ['paid'],
                'productId' => 1,
                'startDate' => '2026-02-01',
                'endDate' => '2026-02-03',
                'timezone' => 'UTC',
            ],
        ]);

        $response->assertSuccessful();

        $data = $response->json('data.orderPaymentStats');
        $this->assertArrayHasKey('period', $data);
        $this->assertArrayHasKey('ordersInPeriod', $data);
        $this->assertArrayHasKey('currentCount', $data);
    }
}
