<?php

declare(strict_types=1);

namespace Tests\Scribe\SalesOrders;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\SalesOrders\Actions\ConfirmDeliveryAction;
use Kanvas\Scribe\SalesOrders\Actions\ConfirmSalesOrderAction;
use Kanvas\Scribe\SalesOrders\Actions\ReturnSalesOrderAction;
use Kanvas\Scribe\SalesOrders\Enums\SalesOrderStatusEnum;
use Kanvas\Scribe\SalesOrders\Kpis\OrderKpiService;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use Tests\Scribe\ScribeTestCase;
use Tests\Traits\CreatesTestSalesOrder;

class OrderKpiTest extends ScribeTestCase
{
    use CreatesTestSalesOrder;

    public function test_the_indicators_count_each_order_by_its_own_event(): void
    {
        $slow = $this->createAcceptedSalesOrder($this->seedTestOrganization('Slow Customer'));
        $slow->forceFill([
            'confirmed_at' => Carbon::now()->subDays(5),
            'accepted_at' => Carbon::now()->subDays(5)->addHours(100),
        ])->save();

        $returned = new ConfirmSalesOrderAction(
            $this->createReviewedSalesOrder($this->seedTestOrganization('Returned Customer')),
            feasibilityConfirmed: true
        )->execute();
        new ReturnSalesOrderAction($returned, 'No container space')->execute();

        $shipped = $this->createShippedSalesOrder($this->seedTestOrganization('Shipped Customer'));
        $shipped->forceFill([
            'confirmed_at' => Carbon::now()->subDays(5),
            'accepted_at' => Carbon::now()->subDays(5)->addHours(10),
        ])->save();
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

    public function test_collections_count_in_the_period_they_were_paid(): void
    {
        $order = $this->createShippedSalesOrder($this->seedTestOrganization('December Shipment Customer'));
        $order->invoice->forceFill([
            'document_status' => InvoiceDocumentStatusEnum::PAID,
            'paid_at' => Carbon::parse('2027-01-05'),
        ])->save();

        $january = collect(new OrderKpiService()->report(
            $this->kanvasApp,
            $this->company,
            Carbon::parse('2027-01-01'),
            Carbon::parse('2027-01-31')
        ))->keyBy('key');

        $this->assertSame(1, $january['collected_on_time']['denominator']);
        $this->assertSame(0, $january['shipped_on_time']['denominator']);
    }

    public function test_an_order_still_waiting_past_72_hours_counts_against_acceptance(): void
    {
        $customer = $this->seedTestOrganization('Acceptance Backlog Customer');
        $accepted = $this->createAcceptedSalesOrder($customer);
        $accepted->forceFill([
            'confirmed_at' => Carbon::now()->subDays(5),
            'accepted_at' => Carbon::now()->subDays(5)->addHours(10),
        ])->save();
        $waiting = new ConfirmSalesOrderAction($this->createReviewedSalesOrder($customer), true)->execute();
        $waiting->forceFill(['confirmed_at' => Carbon::now()->subDays(5)])->save();
        $fresh = new ConfirmSalesOrderAction($this->createReviewedSalesOrder($customer), true)->execute();
        $fresh->forceFill(['confirmed_at' => Carbon::now()->subHours(2)])->save();

        $kpis = collect(new OrderKpiService()->report(
            $this->kanvasApp,
            $this->company,
            Carbon::today()->subDays(7),
            Carbon::today()
        ))->keyBy('key');

        $this->assertSame([1, 2, 50.0, false], $this->read($kpis['acceptance_within_72h']));
    }

    public function test_another_companys_orders_are_not_counted(): void
    {
        SalesOrder::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId() + 987654,
            'order_number' => 'SO-FOREIGN-' . uniqid(),
            'document_status' => SalesOrderStatusEnum::CONFIRMED,
            'po_source' => 'customer_po',
            'customer_organization_id' => $this->seedTestOrganization('Foreign Customer')->getId(),
            'currency' => 'USD',
            'order_total' => 9999,
            'received_at' => Carbon::now(),
            'confirmed_at' => Carbon::now(),
        ]);

        $kpis = new OrderKpiService()->report(
            $this->kanvasApp,
            $this->company,
            Carbon::today()->subDay(),
            Carbon::today()->addDay()
        );

        $this->assertSame([null], array_unique(array_column($kpis, 'value')));
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
