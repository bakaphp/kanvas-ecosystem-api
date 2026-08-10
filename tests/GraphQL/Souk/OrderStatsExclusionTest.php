<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Actions\GetOrderStatsAction;
use Kanvas\Souk\Orders\Enums\OrderStatsExcludeModeEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderTransitionHistory;
use Kanvas\Souk\Orders\Models\OrderTypes;

class OrderStatsExclusionTest extends OrderBase
{
    protected string $orderTypeName = 'reservation';

    /** @var array<string, int> slug => order_status_id */
    private array $statusIds = [];

    private string $variantId;

    public function setUp(): void
    {
        parent::setUp();

        $orderType = OrderTypes::firstOrCreate([
            'name' => $this->orderTypeName,
            'apps_id' => $this->apps->id,
        ]);

        $this->statusIds = $this->createStatuses($this->apps, $orderType, [
            ['slug' => 'draft', 'name' => 'Draft', 'is_default' => true, 'is_final' => false],
            ['slug' => 'in_transit', 'name' => 'In Transit', 'is_default' => false, 'is_final' => false],
            ['slug' => 'released_from_lot', 'name' => 'Released', 'is_default' => false, 'is_final' => true],
            ['slug' => 'cancelled', 'name' => 'Cancelled', 'is_default' => false, 'is_final' => true],
        ]);

        $productResponse = $this->createProduct(attributes: [
            ['name' => 'slots', 'value' => 100],
        ])->json()['data']['createProduct'];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
            attributes: [['name' => 'timezone', 'value' => 'UTC']]
        )->json()['data']['createVariant'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']]
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $this->variantId = $variantResponse['id'];
    }

    private function createStatuses(Apps $app, OrderTypes $orderType, array $statuses): array
    {
        $ids = [];
        foreach ($statuses as $status) {
            $ids[$status['slug']] = OrderStatus::firstOrCreate([
                'order_types_id' => $orderType->id,
                'apps_id' => $app->id,
                'slug' => $status['slug'],
                'name' => $status['name'],
                'is_default' => $status['is_default'],
                'is_final' => $status['is_final'],
            ])->id;
        }

        return $ids;
    }

    private function seedOrder(string $entryAt, string $exitSlug, string $exitAt, string $currentSlug): Order
    {
        $order = $this->createOrderFromCart(
            metadata: ['data' => []],
            variantId: $this->variantId,
            orderType: $this->orderTypeName,
        );

        $this->addTransition($order, 'in_transit', $entryAt);
        $this->addTransition($order, $exitSlug, $exitAt);

        $order->order_status_id = $this->statusIds[$currentSlug];
        $order->saveQuietly();

        return $order;
    }

    private function addTransition(Order $order, string $slug, string $changedAt): void
    {
        OrderTransitionHistory::create([
            'apps_id' => $order->apps_id,
            'companies_id' => $order->companies_id,
            'order_id' => $order->id,
            'from_status_id' => null,
            'to_status_id' => $this->statusIds[$slug],
            'changed_at' => $changedAt,
            'is_current' => false,
            'is_deleted' => 0,
        ]);
    }

    private function turnover(array $excludeStates, OrderStatsExcludeModeEnum $mode): array
    {
        return new GetOrderStatsAction(
            app: $this->apps,
            initialStates: ['in_transit'],
            finalStates: ['released_from_lot'],
            currentCountStates: ['in_transit'],
            excludeStates: $excludeStates,
            excludeMode: $mode,
        )->execute(
            startDate: '2026-06-01',
            endDate: '2026-06-30',
            timezone: 'UTC',
        )['dailyTurnover'];
    }

    public function testCancelledCountsAsEntryWithoutExclusion(): void
    {
        $this->seedOrder('2026-06-05 12:00:00', 'released_from_lot', '2026-06-06 12:00:00', 'released_from_lot');
        $this->seedOrder('2026-06-05 12:00:00', 'cancelled', '2026-06-06 12:00:00', 'cancelled');

        $turnover = $this->turnover([], OrderStatsExcludeModeEnum::CURRENT);

        // Both orders entered in_transit; only the released one is a final exit.
        $this->assertEquals(2, $turnover['totalEntries']);
        $this->assertEquals(1, $turnover['totalExits']);
    }

    public function testCurrentModeExcludesCancelledFromEntriesAndExits(): void
    {
        $this->seedOrder('2026-06-05 12:00:00', 'released_from_lot', '2026-06-06 12:00:00', 'released_from_lot');
        $this->seedOrder('2026-06-05 12:00:00', 'cancelled', '2026-06-06 12:00:00', 'cancelled');

        $turnover = $this->turnover(['cancelled'], OrderStatsExcludeModeEnum::CURRENT);

        // The cancelled order is dropped entirely: it counts as neither entry nor exit.
        $this->assertEquals(1, $turnover['totalEntries']);
        $this->assertEquals(1, $turnover['totalExits']);
    }

    public function testInRangeModeExcludesCancelWithinPeriod(): void
    {
        $this->seedOrder('2026-06-05 12:00:00', 'released_from_lot', '2026-06-06 12:00:00', 'released_from_lot');
        $this->seedOrder('2026-06-05 12:00:00', 'cancelled', '2026-06-06 12:00:00', 'cancelled');

        $turnover = $this->turnover(['cancelled'], OrderStatsExcludeModeEnum::IN_RANGE);

        $this->assertEquals(1, $turnover['totalEntries']);
        $this->assertEquals(1, $turnover['totalExits']);
    }

    public function testInRangeKeepsEntryWhenCancelIsOutsidePeriod(): void
    {
        $this->seedOrder('2026-06-05 12:00:00', 'released_from_lot', '2026-06-06 12:00:00', 'released_from_lot');
        // Entered in June but cancelled in July (outside the queried window).
        $this->seedOrder('2026-06-05 12:00:00', 'cancelled', '2026-07-06 12:00:00', 'cancelled');

        $inRange = $this->turnover(['cancelled'], OrderStatsExcludeModeEnum::IN_RANGE);
        // No cancel transition inside June → the June entry still counts.
        $this->assertEquals(2, $inRange['totalEntries']);

        $current = $this->turnover(['cancelled'], OrderStatsExcludeModeEnum::CURRENT);
        // Current status is cancelled → dropped regardless of when it happened.
        $this->assertEquals(1, $current['totalEntries']);
    }
}
