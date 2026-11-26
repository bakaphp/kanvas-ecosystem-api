<?php

declare(strict_types=1);

namespace Tests\Scribe\SalesOrders;

use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\SalesOrders\Actions\CheckShippingDocumentsAction;
use Kanvas\Scribe\SalesOrders\Actions\CloseSalesOrderAction;
use Kanvas\Scribe\SalesOrders\Actions\ConfirmDeliveryAction;
use Kanvas\Scribe\SalesOrders\Actions\MarkDocumentsPackageSentAction;
use Kanvas\Scribe\SalesOrders\Actions\RecordShipmentAction;
use Kanvas\Scribe\SalesOrders\Actions\ResolveSalesOrderDifferenceAction;
use Kanvas\Scribe\SalesOrders\Enums\DifferenceSourceEnum;
use Kanvas\Scribe\SalesOrders\Enums\SalesOrderStatusEnum;
use Kanvas\Scribe\SalesOrders\Exceptions\InvalidSalesOrderTransitionException;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use Kanvas\Scribe\SalesOrders\Models\SalesOrderShipment;
use Tests\Scribe\ScribeTestCase;
use Tests\Traits\CreatesTestSalesOrder;

class ShipmentTest extends ScribeTestCase
{
    use CreatesTestSalesOrder;

    private const array LOADED = [
        ['quantity' => 10, 'unit_price' => 20],
        ['quantity' => 5, 'unit_price' => 30],
    ];

    public function test_documents_that_agree_leave_no_difference(): void
    {
        [$order, $invoice] = $this->orderWithInvoice();

        $checked = $this->checkDocuments($order, $invoice);

        $this->assertSame([], $checked->documentsDifferences());
        $this->assertSame($invoice->getId(), $checked->invoice_id);
        $this->assertNotNull($checked->documents_checked_at);
    }

    public function test_every_document_that_disagrees_is_listed(): void
    {
        [$order] = $this->orderWithInvoice();
        $customer = $order->customer;
        $invoice = $this->issueInvoiceWithLines($customer, [
            ['quantity' => 10, 'unit_price' => 20],
            ['quantity' => 4, 'unit_price' => 30],
        ]);

        $differences = $this->checkDocuments(
            $order,
            $invoice,
            packingList: [['quantity' => 9], ['quantity' => 4]],
            blTotal: 14
        )->documentsDifferences();

        $this->assertSame(
            ['invoice_total', 'packing_line_1_quantity', 'invoice_line_2_quantity', 'bl_total_quantity'],
            array_column($differences, 'key')
        );
    }

    public function test_an_invoice_of_another_customer_is_refused(): void
    {
        [$order] = $this->orderWithInvoice();
        $stranger = $this->issueInvoiceWithLines($this->seedTestOrganization('Other Customer'), self::LOADED);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('does not belong to this order');

        $this->checkDocuments($order, $stranger);
    }

    public function test_the_bill_of_lading_cannot_be_recorded_before_the_documents_are_checked(): void
    {
        [$order] = $this->orderWithInvoice();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Cross-check the shipping documents');

        $this->record($order);
    }

    public function test_an_open_difference_in_the_documents_blocks_the_bill_of_lading_until_it_is_clarified(): void
    {
        [$order, $invoice] = $this->orderWithInvoice();
        $order = $this->checkDocuments($order, $invoice, blTotal: 14);

        try {
            $this->record($order);
            $this->fail('An open difference should block the bill of lading.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Still open: bl_total_quantity', $e->getMessage());
        }

        $order = new ResolveSalesOrderDifferenceAction(
            $order,
            'bl_total_quantity',
            'Carrier corrected the draft',
            DifferenceSourceEnum::DOCUMENTS
        )->execute();

        $this->assertSame(SalesOrderStatusEnum::ACCEPTED, $order->document_status);
        $this->assertSame('BL-5521', $this->record($order)->bl_number);
    }

    public function test_recording_the_bill_of_lading_ships_the_order_and_sets_the_invoice_due_from_its_date(): void
    {
        [$order, $invoice] = $this->orderWithInvoice();
        $order = $this->checkDocuments($order, $invoice);

        $shipment = $this->record($order, blDate: '2026-12-10', eta: '2026-12-28');

        $this->assertSame(SalesOrderStatusEnum::SHIPPED, $order->fresh()->document_status);
        $this->assertSame('2027-01-29', $shipment->due_date->toDateString());
        $this->assertSame(50, $shipment->payment_terms_days);
        $this->assertSame('2027-01-29', $invoice->fresh()->due_date->toDateString());
        $this->assertSame('2026-12-15', $shipment->documents_package_due_on->toDateString());
    }

    public function test_a_short_route_makes_the_documents_package_due_sooner(): void
    {
        [$order, $invoice] = $this->orderWithInvoice();
        $order = $this->checkDocuments($order, $invoice);

        $shipment = $this->record($order, blDate: '2026-12-10', eta: '2026-12-14');

        $this->assertSame('2026-12-10', $shipment->documents_package_due_on->toDateString());
    }

    public function test_an_order_without_a_payment_term_cannot_ship(): void
    {
        [$order, $invoice] = $this->orderWithInvoice();
        $order = $this->checkDocuments($order, $invoice);
        $order->payment_terms_days = null;
        $order->save();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no payment term');

        $this->record($order);
    }

    public function test_the_documents_package_is_recorded_once_the_order_has_shipped(): void
    {
        [$order, $invoice] = $this->orderWithInvoice();
        $order = $this->checkDocuments($order, $invoice);

        try {
            new MarkDocumentsPackageSentAction($order)->execute();
            $this->fail('An order that has not shipped has no package to send.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('has not shipped', $e->getMessage());
        }

        $this->record($order);
        $first = new MarkDocumentsPackageSentAction($order->fresh())->execute();
        $again = new MarkDocumentsPackageSentAction($order->fresh())->execute();

        $this->assertNotNull($first->documents_package_sent_at);
        $this->assertEquals($first->documents_package_sent_at, $again->documents_package_sent_at);
    }

    public function test_a_delivery_needs_the_proof_of_delivery(): void
    {
        $order = $this->shippedOrder();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('proof of delivery');

        new ConfirmDeliveryAction($order, Carbon::parse('2026-12-28'), '  ')->execute();
    }

    public function test_a_delivery_records_the_pod_and_any_damage(): void
    {
        $order = $this->shippedOrder();

        $shipment = new ConfirmDeliveryAction(
            $order,
            Carbon::parse('2026-12-28'),
            'POD-77',
            ' Two pallets wet ',
            damageReported: true
        )->execute();

        $this->assertSame(SalesOrderStatusEnum::DELIVERED, $order->fresh()->document_status);
        $this->assertSame('POD-77', $shipment->pod_reference);
        $this->assertSame('Two pallets wet', $shipment->delivery_discrepancies);
        $this->assertTrue($shipment->damage_reported);
    }

    public function test_an_order_closes_only_when_delivered_and_paid(): void
    {
        $order = $this->shippedOrder();

        try {
            new CloseSalesOrderAction($order)->execute();
            $this->fail('A shipped order cannot be closed.');
        } catch (InvalidSalesOrderTransitionException) {
            $this->addToAssertionCount(1);
        }

        new ConfirmDeliveryAction($order, Carbon::parse('2026-12-28'), 'POD-78')->execute();
        $delivered = $order->fresh();

        try {
            new CloseSalesOrderAction($delivered)->execute();
            $this->fail('An unpaid invoice should block the close.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('until its invoice is paid', $e->getMessage());
        }

        $delivered->invoice->forceFill(['document_status' => InvoiceDocumentStatusEnum::PAID])->save();

        $this->assertSame(
            SalesOrderStatusEnum::CLOSED,
            new CloseSalesOrderAction($delivered->fresh())->execute()->document_status
        );
    }

    public function test_the_collection_plan_counts_from_the_bill_of_lading_and_puts_the_due_step_at_the_payment_term(): void
    {
        $order = $this->shippedOrder();

        $plan = collect($order->fresh()->collectionPlan());

        $this->assertSame([0, 30, 40, 45, 50, 55, 65, 80], $plan->pluck('day')->all());
        $this->assertSame('2026-12-10', $plan->first()['date']);
        $this->assertSame('2027-01-29', $plan->firstWhere('day', 50)['date']);
        $this->assertSame('Collections', $plan->firstWhere('day', 50)['owner']);
    }

    /**
     * @return array{SalesOrder, Invoice}
     */
    private function orderWithInvoice(): array
    {
        $customer = $this->seedTestOrganization('Shipping Customer');

        return [
            $this->createAcceptedSalesOrder($customer),
            $this->issueInvoiceWithLines($customer, self::LOADED),
        ];
    }

    /**
     * @param list<array{quantity: int|float}>|null $packingList
     */
    private function checkDocuments(
        SalesOrder $order,
        Invoice $invoice,
        ?array $packingList = null,
        int $blTotal = 15,
    ): SalesOrder {
        return new CheckShippingDocumentsAction(
            $order,
            $invoice,
            $packingList ?? [['quantity' => 10], ['quantity' => 5]],
            ['total_quantity' => $blTotal]
        )->execute();
    }

    private function record(SalesOrder $order, string $blDate = '2026-12-10', ?string $eta = null): SalesOrderShipment
    {
        return new RecordShipmentAction(
            $order,
            'BL-5521',
            Carbon::parse($blDate),
            static::$cachedUser,
            vessel: 'Test Vessel',
            containers: ['CONT-1'],
            eta: $eta === null ? null : Carbon::parse($eta),
        )->execute();
    }

    private function shippedOrder(): SalesOrder
    {
        [$order, $invoice] = $this->orderWithInvoice();
        $order = $this->checkDocuments($order, $invoice);
        $this->record($order, eta: '2026-12-28');

        return $order->fresh();
    }
}
