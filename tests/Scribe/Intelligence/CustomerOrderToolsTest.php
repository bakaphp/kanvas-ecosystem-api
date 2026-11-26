<?php

declare(strict_types=1);

namespace Tests\Scribe\Intelligence;

use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\AnswerCustomerOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CancelCustomerOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CheckCustomerCreditTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CheckShippingDocumentsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CloseCustomerOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ConfirmCustomerOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ConfirmDeliveryTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\FindCustomerOrderTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\GetCollectionPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListCustomerOrdersTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\MarkDocumentsPackageSentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\OrderKpisTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\RecordShipmentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\RegisterCustomerPoTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ReleaseShippingDocumentsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ResolvePoDifferenceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ReviewCustomerPoTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\SetCustomerCreditLimitTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\UpdateCustomerOrderTool;
use Kanvas\Scribe\Quotes\Models\Quote;
use NeuronAI\Tools\Tool;
use Tests\Scribe\ScribeTestCase;
use Tests\Traits\AssertsToolRunKeys;
use Tests\Traits\CreatesTestSalesOrder;

class CustomerOrderToolsTest extends ScribeTestCase
{
    use AssertsToolRunKeys;
    use CreatesTestSalesOrder;

    public function test_the_agent_takes_a_customer_po_through_both_gates(): void
    {
        [$customer, $quote] = $this->customerWithQuote();

        $registered = $this->register($customer, $quote, ['incoterm' => 'FOB']);

        $this->assertTrue($registered['registered']);
        $this->assertSame('received', $registered['document_status']);
        $this->assertStringContainsString('review_customer_po', $registered['next']);

        $orderNumber = $registered['order_number'];
        $reviewed = $this->tool(ReviewCustomerPoTool::class)
            ->__invoke(order_number: $orderNumber, agreed_incoterm: 'CIF');

        $this->assertTrue($reviewed['reviewed']);
        $this->assertSame(['incoterm'], $reviewed['open_differences']);
        $this->assertStringContainsString('resolve_po_difference', $reviewed['next']);

        $blocked = $this->tool(ConfirmCustomerOrderTool::class)
            ->__invoke(order_number: $orderNumber, feasibility_confirmed: true);
        $this->assertFalse($blocked['confirmed']);
        $this->assertStringContainsString('Still open: incoterm', $blocked['message']);

        $resolved = $this->tool(ResolvePoDifferenceTool::class)->__invoke(
            order_number: $orderNumber,
            key: 'incoterm',
            resolution: 'Customer agreed to FOB by email',
        );
        $this->assertSame([], $resolved['open_differences']);

        $confirmed = $this->tool(ConfirmCustomerOrderTool::class)
            ->__invoke(order_number: $orderNumber, feasibility_confirmed: true);
        $this->assertTrue($confirmed['confirmed']);
        $this->assertSame('confirmed', $confirmed['document_status']);

        $accepted = $this->tool(AnswerCustomerOrderTool::class)->__invoke(
            order_number: $orderNumber,
            decision: 'accept',
            etd: '2026-12-10',
            eta: '2026-12-28',
        );
        $this->assertTrue($accepted['answered']);
        $this->assertSame('accepted', $accepted['document_status']);
        $this->assertSame('2026-12-28', $accepted['eta']);

        $found = $this->tool(FindCustomerOrderTool::class)->__invoke(order_number: $orderNumber);
        $this->assertTrue($found['found']);
        $this->assertCount(2, $found['lines']);
    }

    public function test_operations_can_return_an_order_and_it_is_listed_by_status(): void
    {
        [$customer, $quote] = $this->customerWithQuote();
        $orderNumber = $this->register($customer, $quote)['order_number'];
        $this->tool(ReviewCustomerPoTool::class)->__invoke(order_number: $orderNumber);
        $this->tool(ConfirmCustomerOrderTool::class)
            ->__invoke(order_number: $orderNumber, feasibility_confirmed: true);

        $noReason = $this->tool(AnswerCustomerOrderTool::class)
            ->__invoke(order_number: $orderNumber, decision: 'return');
        $this->assertFalse($noReason['answered']);

        $returned = $this->tool(AnswerCustomerOrderTool::class)->__invoke(
            order_number: $orderNumber,
            decision: 'return',
            reason: 'No container space',
        );
        $this->assertSame('returned', $returned['document_status']);

        $listed = $this->tool(ListCustomerOrdersTool::class)
            ->__invoke(status: 'returned', customer_organization_id: $customer->getId());
        $this->assertSame([$orderNumber], array_column($listed['sales_orders'], 'order_number'));
    }

    public function test_an_unknown_decision_is_refused(): void
    {
        [$customer, $quote] = $this->customerWithQuote();
        $orderNumber = $this->register($customer, $quote)['order_number'];

        $result = $this->tool(AnswerCustomerOrderTool::class)
            ->__invoke(order_number: $orderNumber, decision: 'maybe');

        $this->assertFalse($result['answered']);
        $this->assertStringContainsString('accept or return', $result['message']);
    }

    public function test_cancelling_reports_the_new_status(): void
    {
        [$customer, $quote] = $this->customerWithQuote();
        $orderNumber = $this->register($customer, $quote)['order_number'];

        $result = $this->tool(CancelCustomerOrderTool::class)
            ->__invoke(order_number: $orderNumber, reason: 'Customer withdrew');

        $this->assertTrue($result['cancelled']);
        $this->assertSame('cancelled', $result['document_status']);
    }

    public function test_an_unknown_customer_quote_or_order_is_reported_not_guessed(): void
    {
        [$customer, $quote] = $this->customerWithQuote();

        $noCustomer = $this->tool(RegisterCustomerPoTool::class)
            ->__invoke(customer_organization_id: 999999999, po_source: 'customer_po');
        $noQuote = $this->register(
            $customer,
            $quote,
            [],
            quoteNumber: 'NO-SUCH-QUOTE'
        );
        $noOrder = $this->tool(FindCustomerOrderTool::class)->__invoke(order_number: 'SO-999999999');

        $this->assertSame('customer_not_found', $noCustomer['reason']);
        $this->assertSame('quote_not_found', $noQuote['reason']);
        $this->assertSame('sales_order_not_found', $noOrder['reason']);
    }

    public function test_a_status_that_does_not_exist_is_refused(): void
    {
        $result = $this->tool(ListCustomerOrdersTool::class)->__invoke(status: 'shipped_maybe');

        $this->assertSame('unknown_status', $result['reason']);
    }

    public function test_the_agent_ships_an_accepted_order_and_follows_it_to_delivery(): void
    {
        $customer = $this->seedTestOrganization('Shipping Tool Customer');
        $orderNumber = $this->createAcceptedSalesOrder($customer)->order_number;
        $invoice = $this->issueInvoiceWithLines($customer, [
            ['quantity' => 10, 'unit_price' => 20],
            ['quantity' => 5, 'unit_price' => 30],
        ]);

        $checked = $this->tool(CheckShippingDocumentsTool::class)->__invoke(
            order_number: $orderNumber,
            invoice_number: $invoice->invoice_number,
            packing_list: [['quantity' => 10], ['quantity' => 5]],
            bl_total_quantity: 15,
        );
        $this->assertTrue($checked['checked']);
        $this->assertSame([], $checked['documents_differences']);
        $this->assertStringContainsString('record_shipment', $checked['next']);

        $shipped = $this->tool(RecordShipmentTool::class)->__invoke(
            order_number: $orderNumber,
            bl_number: 'BL-4401',
            bl_date: '2026-12-10',
            vessel: 'Test Vessel',
            containers: ['CONT-1', 'CONT-2'],
        );
        $this->assertTrue($shipped['shipped']);
        $this->assertSame('shipped', $shipped['document_status']);
        $this->assertSame('2027-01-29', $shipped['shipment']['invoice_due_date']);
        $this->assertContains(50, array_column($shipped['collection_plan'], 'day'));

        $sent = $this->tool(MarkDocumentsPackageSentTool::class)->__invoke(order_number: $orderNumber);
        $this->assertTrue($sent['recorded']);
        $this->assertTrue($sent['shipment']['documents_package_sent']);

        $plan = $this->tool(GetCollectionPlanTool::class)->__invoke(order_number: $orderNumber);
        $this->assertCount(8, $plan['collection_plan']);

        $delivered = $this->tool(ConfirmDeliveryTool::class)->__invoke(
            order_number: $orderNumber,
            delivered_on: '2026-12-28',
            pod_reference: 'POD-4401',
        );
        $this->assertTrue($delivered['delivered']);

        $closed = $this->tool(CloseCustomerOrderTool::class)->__invoke(order_number: $orderNumber);
        $this->assertFalse($closed['closed']);
        $this->assertStringContainsString('until its invoice is paid', $closed['message']);
    }

    public function test_shipping_tools_report_what_is_missing(): void
    {
        $customer = $this->seedTestOrganization('Missing Shipping Customer');
        $orderNumber = $this->createAcceptedSalesOrder($customer)->order_number;

        $noInvoice = $this->tool(CheckShippingDocumentsTool::class)->__invoke(
            order_number: $orderNumber,
            invoice_number: 'NO-SUCH-INVOICE',
            packing_list: [['quantity' => 1]],
            bl_total_quantity: 1,
        );
        $notChecked = $this->tool(RecordShipmentTool::class)->__invoke(
            order_number: $orderNumber,
            bl_number: 'BL-1',
            bl_date: '2026-12-10',
        );
        $noPlan = $this->tool(GetCollectionPlanTool::class)->__invoke(order_number: $orderNumber);
        $badSource = $this->tool(ResolvePoDifferenceTool::class)->__invoke(
            order_number: $orderNumber,
            key: 'x',
            resolution: 'y',
            source: 'elsewhere',
        );

        $this->assertSame('invoice_not_found', $noInvoice['reason']);
        $this->assertFalse($notChecked['shipped']);
        $this->assertStringContainsString('Cross-check the shipping documents', $notChecked['message']);
        $this->assertSame('not_shipped', $noPlan['reason']);
        $this->assertSame('unknown_source', $badSource['reason']);
    }

    public function test_the_agent_sets_a_credit_limit_and_the_confirmation_respects_it(): void
    {
        [$customer, $quote] = $this->customerWithQuote();

        $set = $this->tool(SetCustomerCreditLimitTool::class)->__invoke(
            customer_organization_id: $customer->getId(),
            amount: 300,
            currency: 'usd',
        );
        $this->assertTrue($set['set']);
        $this->assertSame(300.0, $set['available']);

        $credit = $this->tool(CheckCustomerCreditTool::class)->__invoke(customer_organization_id: $customer->getId());
        $this->assertTrue($credit['has_limit']);
        $this->assertFalse($credit['on_hold']);

        $orderNumber = $this->register($customer, $quote)['order_number'];
        $this->tool(ReviewCustomerPoTool::class)->__invoke(order_number: $orderNumber);
        $refused = $this->tool(ConfirmCustomerOrderTool::class)->__invoke(order_number: $orderNumber, feasibility_confirmed: true);

        $this->assertFalse($refused['confirmed']);
        $this->assertStringContainsString('still available', $refused['message']);

        $removed = $this->tool(SetCustomerCreditLimitTool::class)->__invoke(customer_organization_id: $customer->getId());
        $this->assertFalse($removed['has_limit']);
    }

    public function test_only_an_administrator_can_set_a_credit_limit(): void
    {
        [$customer] = $this->customerWithQuote();

        $refused = $this->tool(SetCustomerCreditLimitTool::class)
            ->forRequestingUser($this->seedTestEmployee())
            ->__invoke(customer_organization_id: $customer->getId(), amount: 300, currency: 'USD');

        $this->assertFalse($refused['set']);
        $this->assertSame('admin_required', $refused['reason']);

        $credit = $this->tool(CheckCustomerCreditTool::class)->__invoke(customer_organization_id: $customer->getId());
        $this->assertFalse($credit['has_limit']);
    }

    public function test_a_credit_limit_without_a_currency_and_an_unknown_customer_are_reported(): void
    {
        [$customer] = $this->customerWithQuote();

        $noCurrency = $this->tool(SetCustomerCreditLimitTool::class)->__invoke(
            customer_organization_id: $customer->getId(),
            amount: 100,
        );
        $unknown = $this->tool(CheckCustomerCreditTool::class)->__invoke(customer_organization_id: 999999999);

        $this->assertFalse($noCurrency['set']);
        $this->assertStringContainsString('three-letter currency', $noCurrency['message']);
        $this->assertSame('customer_not_found', $unknown['reason']);
    }

    public function test_the_agent_releases_the_documents_of_a_shipped_order(): void
    {
        $order = $this->createShippedSalesOrder($this->seedTestOrganization('Release Tool Customer'));

        $released = $this->tool(ReleaseShippingDocumentsTool::class)->__invoke(order_number: $order->order_number);
        $notShipped = $this->tool(ReleaseShippingDocumentsTool::class)->__invoke(
            order_number: $this->createAcceptedSalesOrder($this->seedTestOrganization('Unshipped Tool Customer'))->order_number
        );

        $this->assertTrue($released['released']);
        $this->assertFalse($notShipped['released']);
        $this->assertStringContainsString('has not shipped', $notShipped['message']);
    }

    public function test_the_order_indicators_are_read_for_a_valid_period_only(): void
    {
        $report = $this->tool(OrderKpisTool::class)->__invoke(from: '2026-05-01', to: '2027-02-28');
        $backwards = $this->tool(OrderKpisTool::class)->__invoke(from: '2027-02-28', to: '2026-05-01');
        $garbage = $this->tool(OrderKpisTool::class)->__invoke(from: 'when', to: 'ever');

        $this->assertCount(8, $report['indicators']);
        $this->assertSame('invalid_period', $backwards['reason']);
        $this->assertSame('invalid_period', $garbage['reason']);
    }

    public function test_the_agent_corrects_a_returned_order_and_it_goes_back_to_review(): void
    {
        [$customer, $quote] = $this->customerWithQuote();
        $orderNumber = $this->register($customer, $quote)['order_number'];
        $this->tool(ReviewCustomerPoTool::class)->__invoke(order_number: $orderNumber);
        $this->tool(ConfirmCustomerOrderTool::class)->__invoke(order_number: $orderNumber, feasibility_confirmed: true);
        $returned = $this->tool(AnswerCustomerOrderTool::class)->__invoke(
            order_number: $orderNumber,
            decision: 'return',
            reason: 'No container space',
        );
        $this->assertStringContainsString('update_customer_order', $returned['next']);

        $corrected = $this->tool(UpdateCustomerOrderTool::class)->__invoke(
            order_number: $orderNumber,
            requested_date: '2027-01-20',
            notes: 'Customer accepted a later date',
        );

        $this->assertTrue($corrected['updated']);
        $this->assertSame('received', $corrected['document_status']);
        $this->assertStringContainsString('review_customer_po', $corrected['next']);

        $unknownQuote = $this->tool(UpdateCustomerOrderTool::class)->__invoke(
            order_number: $orderNumber,
            quote_number: 'NO-SUCH-QUOTE',
        );
        $this->assertSame('quote_not_found', $unknownQuote['reason']);
    }

    public function test_the_sales_order_tools_key_their_run_budget_by_inputs(): void
    {
        foreach ([
            RegisterCustomerPoTool::class,
            UpdateCustomerOrderTool::class,
            ReviewCustomerPoTool::class,
            ResolvePoDifferenceTool::class,
            CheckShippingDocumentsTool::class,
            RecordShipmentTool::class,
            MarkDocumentsPackageSentTool::class,
            ConfirmDeliveryTool::class,
            CloseCustomerOrderTool::class,
            GetCollectionPlanTool::class,
            ConfirmCustomerOrderTool::class,
            AnswerCustomerOrderTool::class,
            CancelCustomerOrderTool::class,
            SetCustomerCreditLimitTool::class,
            CheckCustomerCreditTool::class,
            ReleaseShippingDocumentsTool::class,
            OrderKpisTool::class,
        ] as $tool) {
            $this->assertRunKeyFollowsInputs(new $tool());
        }
    }

    public function test_listing_orders_loads_shipments_together(): void
    {
        $customer = $this->seedTestOrganization('Batch Shipped Customer');
        $first = $this->createShippedSalesOrder($customer);
        $second = $this->createReviewedSalesOrder($customer);
        $connection = DB::connection('accounting');
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $result = $this->tool(ListCustomerOrdersTool::class)->__invoke(customer_organization_id: $customer->getId());
            $queries = $connection->getQueryLog();
        } finally {
            $connection->disableQueryLog();
        }

        $listed = collect($result['sales_orders'])->keyBy('order_number');
        $this->assertSame(2, $result['count']);
        $this->assertSame($first->shipment->bl_number, $listed[$first->order_number]['shipment']['bl_number']);
        $this->assertNull($listed[$second->order_number]['shipment']);
        $this->assertCount(1, array_filter(
            $queries,
            static fn (array $query): bool => str_contains($query['query'], 'from `sales_order_shipments`')
        ));
    }

    /**
     * @template T of Tool
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function tool(string $class): Tool
    {
        return new $class()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function register(
        Organization $customer,
        Quote $quote,
        array $extra = [],
        ?string $quoteNumber = null,
    ): array {
        return $this->tool(RegisterCustomerPoTool::class)->__invoke(...[
            'customer_organization_id' => $customer->getId(),
            'po_source' => 'customer_po',
            'quote_number' => $quoteNumber ?? $quote->quote_number,
            'customer_po_number' => 'PO-' . uniqid(),
            'customer_po_date' => '2026-11-20',
            'currency' => 'USD',
            'lines' => [
                ['description' => 'Pallet of rice', 'quantity' => 10, 'unit_price' => 20, 'sku' => 'RICE-1'],
                ['description' => 'Pallet of beans', 'quantity' => 5, 'unit_price' => 30, 'sku' => 'BEAN-1'],
            ],
            'signature_checklist' => ['authorized_signer' => true],
        ] + $extra);
    }

    /**
     * @return array{Organization, Quote}
     */
    private function customerWithQuote(): array
    {
        $customer = $this->seedTestOrganization('Tool Customer');

        return [$customer, $this->createTestQuote($customer)];
    }
}
