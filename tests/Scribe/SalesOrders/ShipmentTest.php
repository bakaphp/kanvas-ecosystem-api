<?php

declare(strict_types=1);

namespace Tests\Scribe\SalesOrders;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Invoices\Actions\AllocateInvoicePaymentAction;
use Kanvas\Scribe\Invoices\Actions\UpdateInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\VoidInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Exceptions\InvalidInvoiceTransitionException;
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
use Kanvas\Scribe\SalesOrders\Services\ShippingDocumentsCheckService;
use Override;
use Spatie\LaravelData\DataCollection;
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

    public function test_a_shipment_needs_its_bill_of_lading_number(): void
    {
        [$order, $invoice] = $this->orderWithInvoice();
        $order = $this->checkDocuments($order, $invoice);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('number of its bill of lading');

        new RecordShipmentAction(
            order: $order,
            blNumber: '  ',
            blDate: Carbon::parse('2026-12-10'),
            user: static::$cachedUser,
        )->execute();
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

    public function test_an_invoice_in_another_currency_is_a_difference(): void
    {
        $order = $this->createAcceptedSalesOrder($this->seedTestOrganization('Currency Customer'));

        $checked = $this->checkDocuments($order, $this->draftInvoiceFor($order, 'EUR'));

        $this->assertContains('invoice_currency', array_column($checked->documentsDifferences(), 'key'));
    }

    public function test_a_credit_note_is_not_a_commercial_invoice(): void
    {
        $order = $this->createAcceptedSalesOrder($this->seedTestOrganization('Note Customer'));
        $note = $this->draftInvoiceFor($order);
        $note->forceFill(['document_type' => DocumentTypeEnum::CREDIT_NOTE])->save();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not a commercial invoice');

        $this->checkDocuments($order, $note);
    }

    public function test_one_invoice_cannot_cover_two_orders(): void
    {
        $customer = $this->seedTestOrganization('Shared Invoice Customer');
        $first = $this->createAcceptedSalesOrder($customer);
        $second = $this->createAcceptedSalesOrder($customer);
        $invoice = $this->issueInvoiceWithLines($customer, self::LOADED);
        $this->checkDocuments($first, $invoice);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("already covers sales order {$first->order_number}");

        $this->checkDocuments($second, $invoice);
    }

    public function test_a_shipped_order_keeps_its_checked_invoice(): void
    {
        $order = $this->shippedOrder();

        $this->expectException(InvalidSalesOrderTransitionException::class);

        $this->checkDocuments($order, $this->draftInvoiceFor($order));
    }

    public function test_an_invoice_edited_after_the_check_has_to_be_checked_again(): void
    {
        $order = $this->createAcceptedSalesOrder($this->seedTestOrganization('Edited Invoice Customer'));
        $invoice = $this->draftInvoiceFor($order);
        $order = $this->checkDocuments($order, $invoice);
        $this->assertSame([], $order->openDocumentsDifferenceKeys());

        new UpdateInvoiceAction(
            $invoice,
            InvoiceData::forUpdate($invoice, [
                new InvoiceLineData(
                    description: 'Pallet of rice',
                    quantity: 1,
                    unit_price_native: 20,
                    sku: 'RICE-1'
                ),
                new InvoiceLineData(
                    description: 'Pallet of beans',
                    quantity: 5,
                    unit_price_native: 30,
                    sku: 'BEAN-1'
                ),
            ]),
            static::$cachedUser
        )->execute();

        try {
            $this->record($order);
            $this->fail('An invoice edited after the check should block the bill of lading.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('changed after its shipping documents were checked', $e->getMessage());
        }

        $rechecked = $this->checkDocuments($order, $invoice->fresh());
        $this->assertContains('invoice_line_1_quantity', $rechecked->openDocumentsDifferenceKeys());
    }

    public function test_an_invoice_naming_other_products_is_a_difference(): void
    {
        $order = $this->createAcceptedSalesOrder($this->seedTestOrganization('Wrong Products Customer'));
        $invoice = $this->draftInvoiceFor($order);
        new UpdateInvoiceAction(
            $invoice,
            InvoiceData::forUpdate($invoice, [
                new InvoiceLineData(
                    description: 'Cement',
                    quantity: 10,
                    unit_price_native: 20,
                    sku: 'CEMENT-1'
                ),
                new InvoiceLineData(
                    description: 'Sand',
                    quantity: 5,
                    unit_price_native: 30,
                    sku: 'SAND-1'
                ),
            ]),
            static::$cachedUser
        )->execute();

        $keys = $this->checkDocuments($order, $invoice->fresh())->openDocumentsDifferenceKeys();

        $this->assertSame(['invoice_line_1_product', 'invoice_line_2_product'], $keys);
    }

    public function test_an_invoice_without_product_codes_is_matched_on_its_descriptions(): void
    {
        $customer = $this->seedTestOrganization('No Codes Customer');
        $order = $this->createAcceptedSalesOrder($customer);
        $invoice = $this->draftInvoiceFor($order);
        $invoice->lines()->update(['sku' => null]);

        $this->assertSame([], $this->checkDocuments($order, $invoice->fresh())->openDocumentsDifferenceKeys());
    }

    public function test_a_delivery_cannot_be_before_the_bill_of_lading(): void
    {
        $order = $this->shippedOrder();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('cannot be before the bill of lading date');

        new ConfirmDeliveryAction($order, $order->shipment->bl_date->copy()->subDay(), 'POD-TOO-EARLY')->execute();
    }

    public function test_an_order_paid_before_departure_still_ships(): void
    {
        [$order, $invoice] = $this->orderWithInvoice();
        new AllocateInvoicePaymentAction($invoice, 350, user: static::$cachedUser)->execute();
        $this->assertSame(InvoiceDocumentStatusEnum::PAID, $invoice->fresh()->document_status);
        $dueBefore = $invoice->fresh()->due_date->toDateString();

        $shipment = $this->record($this->checkDocuments($order, $invoice->fresh()));

        $this->assertNotNull($shipment->getKey());
        $this->assertSame($dueBefore, $invoice->fresh()->due_date->toDateString());
    }

    public function test_a_stale_copy_of_an_accepted_order_cannot_replace_the_invoice_after_shipping(): void
    {
        [$order, $invoice] = $this->orderWithInvoice();
        $order = $this->checkDocuments($order, $invoice);
        $stale = $order->fresh();
        $this->record($order);

        $this->expectException(InvalidSalesOrderTransitionException::class);

        $this->checkDocuments($stale, $this->draftInvoiceFor($order));
    }

    public function test_a_second_order_checked_meanwhile_cannot_claim_the_same_invoice(): void
    {
        $customer = $this->seedTestOrganization('Overlapping Claim Customer');
        $first = $this->createAcceptedSalesOrder($customer);
        $second = $this->createAcceptedSalesOrder($customer);
        $invoice = $this->draftInvoiceFor($first);
        $interleaved = new class ($second) extends ShippingDocumentsCheckService {
            public function __construct(private readonly SalesOrder $second)
            {
            }

            #[Override]
            public function differences(
                SalesOrder $order,
                Invoice $invoice,
                array $packingList,
                array $blDraft
            ): array {
                new CheckShippingDocumentsAction(
                    $this->second,
                    $invoice,
                    $packingList,
                    $blDraft
                )->execute();

                return parent::differences(
                    $order,
                    $invoice,
                    $packingList,
                    $blDraft
                );
            }
        };

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("already covers sales order {$first->order_number}");

        new CheckShippingDocumentsAction(
            $first,
            $invoice,
            [['quantity' => 10], ['quantity' => 5]],
            ['total_quantity' => 15],
            check: $interleaved,
        )->execute();
    }

    public function test_an_invoice_moved_to_another_customer_after_the_check_has_to_be_checked_again(): void
    {
        $order = $this->orderCheckedAgainstADraft('Reassigned Invoice Customer');
        $this->rewriteInvoice(
            $order->invoice,
            $this->seedTestOrganization('Somebody Else'),
            DocumentTypeEnum::INVOICE
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('changed after its shipping documents were checked');

        $this->record($order);
    }

    public function test_an_invoice_turned_into_a_credit_note_after_the_check_has_to_be_checked_again(): void
    {
        $order = $this->orderCheckedAgainstADraft('Credit Note Customer');
        $this->rewriteInvoice($order->invoice, $order->customer, DocumentTypeEnum::CREDIT_NOTE);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('changed after its shipping documents were checked');

        $this->record($order);
    }

    public function test_the_order_and_the_invoice_are_locked_before_anything_is_read_for_the_shipment(): void
    {
        $order = $this->orderCheckedAgainstADraft('Locked Invoice Customer');
        $this->issueDraftInvoice($order->invoice);

        $queries = $this->accountingQueriesOf(fn () => $this->record($order));
        $invoiceLock = $this->firstMatching($queries, '/from `invoices` .*for update$/');
        $reads = array_filter(
            array_slice($queries, 0, (int) $invoiceLock + 1),
            fn (string $query): bool => str_starts_with($query, 'select')
        );

        $this->assertNotNull($invoiceLock, 'The shipment never locked its invoice.');
        $this->assertLessThan($this->firstMatching($queries, '/from `invoice_lines`/'), $invoiceLock);
        $this->assertSame([], array_filter($reads, fn (string $query): bool => ! str_ends_with($query, 'for update')));
    }

    public function test_goods_ship_against_an_issued_invoice_never_a_draft(): void
    {
        $order = $this->orderCheckedAgainstADraft('Draft Invoice Customer');
        $invoice = $order->invoice;

        try {
            $this->record($order);
            $this->fail('A draft invoice should block the bill of lading.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('still a draft: issue it', $e->getMessage());
        }

        $this->assertSame(SalesOrderStatusEnum::ACCEPTED, $order->fresh()->document_status);

        $this->issueDraftInvoice($invoice);
        $shipment = $this->record($order->fresh());

        $this->assertSame(SalesOrderStatusEnum::SHIPPED, $order->fresh()->document_status);
        $this->assertSame($shipment->due_date->toDateString(), $invoice->fresh()->due_date->toDateString());
    }

    public function test_the_invoice_of_a_shipped_order_can_no_longer_be_edited(): void
    {
        $order = $this->shippedOrder();

        $this->expectException(InvalidInvoiceTransitionException::class);

        $this->rewriteInvoice($order->invoice, $order->customer, DocumentTypeEnum::INVOICE);
    }

    public function test_an_invoice_voided_after_the_check_blocks_the_bill_of_lading(): void
    {
        [$order, $invoice] = $this->orderWithInvoice();
        $order = $this->checkDocuments($order, $invoice);
        new VoidInvoiceAction($invoice->fresh(), 'Wrong consignee', static::$cachedUser)->execute();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('is voided: check the shipping documents against the invoice that replaces it');

        $this->record($order);
    }

    public function test_a_bill_of_lading_cannot_be_recorded_twice(): void
    {
        $order = $this->shippedOrder();

        $this->expectException(InvalidSalesOrderTransitionException::class);
        $this->expectExceptionMessage('already shipped');

        $this->record($order);
    }

    private function orderCheckedAgainstADraft(string $customerName): SalesOrder
    {
        $order = $this->createAcceptedSalesOrder($this->seedTestOrganization($customerName));

        return $this->checkDocuments($order, $this->draftInvoiceFor($order));
    }

    private function rewriteInvoice(Invoice $invoice, Organization $billable, DocumentTypeEnum $type): void
    {
        new UpdateInvoiceAction(
            $invoice,
            new InvoiceData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $billable,
                lines: new DataCollection(InvoiceLineData::class, [
                    new InvoiceLineData(
                        description: 'Pallet of rice',
                        quantity: 10,
                        unit_price_native: 20,
                        sku: 'RICE-1'
                    ),
                    new InvoiceLineData(
                        description: 'Pallet of beans',
                        quantity: 5,
                        unit_price_native: 30,
                        sku: 'BEAN-1'
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                document_type: $type,
            ),
            static::$cachedUser
        )->execute();
    }

    /**
     * @return list<string>
     */
    private function accountingQueriesOf(Closure $work): array
    {
        $accounting = DB::connection('accounting');
        $accounting->flushQueryLog();
        $accounting->enableQueryLog();

        try {
            $work();
        } finally {
            $accounting->disableQueryLog();
        }

        return array_column($accounting->getQueryLog(), 'query');
    }

    /**
     * @param list<string> $queries
     */
    private function firstMatching(array $queries, string $pattern): ?int
    {
        return array_key_first(preg_grep($pattern, $queries)) ?? null;
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
