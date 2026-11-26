<?php

declare(strict_types=1);

namespace Tests\Scribe\SalesOrders;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\SalesOrders\Actions\ConfirmDeliveryAction;
use Kanvas\Scribe\SalesOrders\Actions\ConfirmSalesOrderAction;
use Kanvas\Scribe\SalesOrders\Actions\ReturnSalesOrderAction;
use Kanvas\Scribe\SalesOrders\Kpis\OrderKpiService;
use Tests\Scribe\ScribeTestCase;
use Tests\Traits\CreatesTestSalesOrder;

class OrderKpiTest extends ScribeTestCase
{
    use CreatesTestSalesOrder;

    public function test_the_indicators_count_each_order_by_its_own_event(): void
    {
        $slow = $this->createAcceptedSalesOrder($this->seedTestOrganization('Slow Customer'));
        $slow->forceFill(['confirmed_at' => Carbon::now()->subDays(5), 'accepted_at' => Carbon::now()->subDays(5)->addHours(100)])->save();

        $returned = new ConfirmSalesOrderAction(
            $this->createReviewedSalesOrder($this->seedTestOrganization('Returned Customer')),
            feasibilityConfirmed: true
        )->execute();
        new ReturnSalesOrderAction($returned, 'No container space')->execute();

        $shipped = $this->createShippedSalesOrder($this->seedTestOrganization('Shipped Customer'));
        $shipped->forceFill(['confirmed_at' => Carbon::now()->subDays(5), 'accepted_at' => Carbon::now()->subDays(5)->addHours(10)])->save();
        new ConfirmDeliveryAction($shipped, Carbon::parse('2026-12-28'), 'POD-1')->execute();
        $shipped->invoice->forceFill([
            'document_status' => InvoiceDocumentStatusEnum::PAID,
            'paid_at' => Carbon::parse('2027-01-01'),
        ])->save();

        $kpis = collect(new OrderKpiService()->report(
            $this->kanvasApp,
            $this->company,
            Carbon::parse('2026-05-01'),
            Carbon::parse('2027-02-28')
        ))->keyBy('key');

        $this->assertSame([1, 3, 33.3, false], $this->read($kpis['acceptance_within_72h']));
        $this->assertSame([1, 3, 33.3, false], $this->read($kpis['returned_at_gate_2']));
        $this->assertSame([3, 3, 100.0, true], $this->read($kpis['right_first_time']));
        $this->assertSame([1, 1, 100.0, true], $this->read($kpis['shipped_on_time']));
        $this->assertSame([0, 0, null, null], $this->read($kpis['documents_on_time']));
        $this->assertSame([1, 1, 100.0, true], $this->read($kpis['perfect_delivery']));
        $this->assertSame([1, 1, 100.0, true], $this->read($kpis['collected_on_time']));
        $this->assertSame(22.0, $kpis['average_days_to_collect']['value']);
        $this->assertSame(50.0, $kpis['average_days_to_collect']['target']);
        $this->assertTrue($kpis['average_days_to_collect']['met']);
    }

    public function test_a_period_without_orders_has_no_values(): void
    {
        $kpis = new OrderKpiService()->report(
            $this->kanvasApp,
            $this->company,
            Carbon::parse('2020-01-01'),
            Carbon::parse('2020-01-31')
        );

        $this->assertCount(8, $kpis);
        $this->assertSame([null], array_unique(array_column($kpis, 'value')));
        $this->assertSame([null], array_unique(array_column($kpis, 'met')));
    }

    /**
     * @param array<string, mixed> $kpi
     *
     * @return array{int|null, int|null, float|null, bool|null}
     */
    private function read(array $kpi): array
    {
        return [$kpi['numerator'], $kpi['denominator'], $kpi['value'], $kpi['met']];
    }
}
