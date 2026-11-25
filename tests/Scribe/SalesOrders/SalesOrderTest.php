<?php

declare(strict_types=1);

namespace Tests\Scribe\SalesOrders;

use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\SalesOrders\Actions\AcceptSalesOrderAction;
use Kanvas\Scribe\SalesOrders\Actions\CancelSalesOrderAction;
use Kanvas\Scribe\SalesOrders\Actions\ConfirmSalesOrderAction;
use Kanvas\Scribe\SalesOrders\Actions\RegisterCustomerPoAction;
use Kanvas\Scribe\SalesOrders\Actions\ResolveSalesOrderDifferenceAction;
use Kanvas\Scribe\SalesOrders\Actions\ReturnSalesOrderAction;
use Kanvas\Scribe\SalesOrders\Actions\ReviewSalesOrderAction;
use Kanvas\Scribe\SalesOrders\Actions\UpdateSalesOrderAction;
use Kanvas\Scribe\SalesOrders\DataTransferObject\SalesOrder as SalesOrderData;
use Kanvas\Scribe\SalesOrders\Enums\SalesOrderStatusEnum;
use Kanvas\Scribe\SalesOrders\Exceptions\InvalidSalesOrderTransitionException;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use Kanvas\Scribe\SalesOrders\Services\SalesOrderStateMachineService;
use Tests\Scribe\ScribeTestCase;
use Tests\Traits\CreatesTestQuote;

class SalesOrderTest extends ScribeTestCase
{
    use CreatesTestQuote;

    private const array SIGNED = ['authorized_signer' => true];

    private const array LINES = [
        ['description' => 'Pallet of rice', 'quantity' => 10, 'unit_price' => 20, 'sku' => 'RICE-1'],
        ['description' => 'Pallet of beans', 'quantity' => 5, 'unit_price' => 30, 'sku' => 'BEAN-1'],
    ];

    public function test_a_customer_po_is_registered_as_a_numbered_received_order(): void
    {
        [$customer, $quote] = $this->customerWithQuote();

        $order = $this->register($customer, $quote);

        $this->assertStringStartsWith('SO-', $order->order_number);
        $this->assertSame(SalesOrderStatusEnum::RECEIVED, $order->document_status);
        $this->assertSame('350.0000', $order->order_total);
        $this->assertSame('PO-9001', $order->customer_po_number);
        $this->assertCount(2, $order->lines);
        $this->assertNotNull($order->received_at);
    }

    public function test_the_same_po_cannot_be_registered_twice_for_a_customer(): void
    {
        [$customer, $quote] = $this->customerWithQuote();
        $this->register($customer, $quote);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already registered');

        $this->register($customer, $quote);
    }

    public function test_a_customer_po_needs_its_date(): void
    {
        [$customer, $quote] = $this->customerWithQuote();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('needs its date');

        $this->register($customer, $quote, ['customer_po_date' => null]);
    }

    public function test_a_signed_proforma_takes_its_lines_currency_and_number_from_the_quote(): void
    {
        [$customer, $quote] = $this->customerWithQuote();

        $order = new RegisterCustomerPoAction(
            SalesOrderData::fromMultiple(
                $this->kanvasApp,
                static::$cachedUser,
                $this->company,
                ['customer_id' => $customer->getId(), 'po_source' => 'signed_proforma', 'quote_id' => $quote->getId()]
            )
        )->execute();

        $this->assertSame($quote->quote_number, $order->customer_po_number);
        $this->assertSame('USD', $order->currency);
        $this->assertCount(2, $order->lines);
        $this->assertSame('350.0000', $order->order_total);
    }

    public function test_a_review_that_finds_nothing_leaves_no_differences(): void
    {
        [$customer, $quote] = $this->customerWithQuote();
        $order = $this->register($customer, $quote);

        $reviewed = new ReviewSalesOrderAction($order)->execute();

        $this->assertNotNull($reviewed->reviewed_at);
        $this->assertSame([], $reviewed->differences());
    }

    public function test_a_review_lists_every_difference_with_the_quote_and_the_agreement(): void
    {
        [$customer, $quote] = $this->customerWithQuote();
        $order = $this->register($customer, $quote, [
            'lines' => [
                ['description' => 'Pallet of rice', 'quantity' => 12, 'unit_price' => 20, 'sku' => 'RICE-1'],
                ['description' => 'Pallet of beans', 'quantity' => 5, 'unit_price' => 28, 'sku' => 'BEAN-1'],
                ['description' => 'Extra pallet', 'quantity' => 1, 'unit_price' => 10],
            ],
            'incoterm' => 'FOB',
        ]);

        $differences = new ReviewSalesOrderAction($order, ['incoterm' => 'CIF'])->execute()->differences();

        $this->assertSame(
            ['total', 'line_1_quantity', 'line_2_unit_price', 'line_3_missing_in_quote', 'incoterm'],
            array_column($differences, 'key')
        );
        $this->assertSame(['12', '10'], [$differences[1]['po_value'], $differences[1]['quote_value']]);
        $this->assertSame(['FOB', 'CIF'], [$differences[4]['po_value'], $differences[4]['quote_value']]);
    }

    public function test_an_order_cannot_be_confirmed_before_it_is_reviewed(): void
    {
        [$customer, $quote] = $this->customerWithQuote();
        $order = $this->register($customer, $quote);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Review sales order');

        new ConfirmSalesOrderAction($order, feasibilityConfirmed: true)->execute();
    }

    public function test_an_order_with_an_open_difference_cannot_be_confirmed_until_it_is_clarified(): void
    {
        [$customer, $quote] = $this->customerWithQuote();
        $order = $this->register($customer, $quote, ['incoterm' => 'FOB']);
        $order = new ReviewSalesOrderAction($order, ['incoterm' => 'CIF'])->execute();

        try {
            new ConfirmSalesOrderAction($order, feasibilityConfirmed: true)->execute();
            $this->fail('An open difference should block the confirmation.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Still open: incoterm', $e->getMessage());
        }

        $order = new ResolveSalesOrderDifferenceAction($order, 'incoterm', 'Customer agreed to FOB by email')->execute();

        $this->assertSame(
            SalesOrderStatusEnum::CONFIRMED,
            new ConfirmSalesOrderAction($order, feasibilityConfirmed: true)->execute()->document_status
        );
    }

    public function test_a_difference_that_does_not_exist_cannot_be_resolved(): void
    {
        [$customer, $quote] = $this->customerWithQuote();
        $order = new ReviewSalesOrderAction($this->register($customer, $quote))->execute();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('has no difference');

        new ResolveSalesOrderDifferenceAction($order, 'no_such_key', 'Whatever')->execute();
    }

    public function test_a_signed_proforma_needs_the_whole_validity_checklist(): void
    {
        [$customer, $quote] = $this->customerWithQuote();
        $order = new RegisterCustomerPoAction(
            SalesOrderData::fromMultiple(
                $this->kanvasApp,
                static::$cachedUser,
                $this->company,
                [
                    'customer_id' => $customer->getId(),
                    'po_source' => 'signed_proforma',
                    'quote_id' => $quote->getId(),
                    'signature_checklist' => ['authorized_signer' => true, 'all_pages_initialed' => true],
                ]
            )
        )->execute();
        $order = new ReviewSalesOrderAction($order)->execute();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('legible_stamp_and_date, matching_legal_name_and_tax_id');

        new ConfirmSalesOrderAction($order, feasibilityConfirmed: true)->execute();
    }

    public function test_a_customer_po_without_the_authorized_signature_is_not_valid(): void
    {
        [$customer, $quote] = $this->customerWithQuote();
        $order = new ReviewSalesOrderAction($this->register($customer, $quote, ['signature_checklist' => []]))->execute();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('authorized_signer');

        new ConfirmSalesOrderAction($order, feasibilityConfirmed: true)->execute();
    }

    public function test_operations_has_to_confirm_feasibility_before_the_order_is_confirmed(): void
    {
        [$customer, $quote] = $this->customerWithQuote();
        $order = new ReviewSalesOrderAction($this->register($customer, $quote))->execute();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('feasible');

        new ConfirmSalesOrderAction($order, feasibilityConfirmed: false)->execute();
    }

    public function test_operations_accepts_a_confirmed_order_with_firm_dates(): void
    {
        $order = $this->confirmedOrder();

        $accepted = new AcceptSalesOrderAction($order, Carbon::parse('2026-12-10'), Carbon::parse('2026-12-28'))->execute();

        $this->assertSame(SalesOrderStatusEnum::ACCEPTED, $accepted->document_status);
        $this->assertSame('2026-12-10', $accepted->etd->toDateString());
        $this->assertSame('2026-12-28', $accepted->eta->toDateString());
        $this->assertNotNull($accepted->accepted_at);
    }

    public function test_arrival_cannot_be_before_departure(): void
    {
        $order = $this->confirmedOrder();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('before the departure');

        new AcceptSalesOrderAction($order, Carbon::parse('2026-12-10'), Carbon::parse('2026-12-01'))->execute();
    }

    public function test_an_order_that_was_not_confirmed_cannot_be_accepted(): void
    {
        [$customer, $quote] = $this->customerWithQuote();
        $order = $this->register($customer, $quote);

        $this->expectException(InvalidSalesOrderTransitionException::class);

        new AcceptSalesOrderAction($order, Carbon::parse('2026-12-10'), Carbon::parse('2026-12-28'))->execute();
    }

    public function test_a_returned_order_needs_a_reason_and_goes_back_to_commercial_when_edited(): void
    {
        $order = $this->confirmedOrder();

        try {
            new ReturnSalesOrderAction($order, '   ')->execute();
            $this->fail('A return without a reason should be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Say why', $e->getMessage());
        }

        $returned = new ReturnSalesOrderAction($order, 'Cannot fill the container by the requested date')->execute();
        $this->assertSame(SalesOrderStatusEnum::RETURNED, $returned->document_status);

        $edited = new UpdateSalesOrderAction(
            $returned,
            SalesOrderData::forUpdate(
                $returned,
                $this->kanvasApp,
                static::$cachedUser,
                $this->company,
                ['requested_date' => '2027-01-20']
            )
        )->execute();

        $this->assertSame(SalesOrderStatusEnum::RECEIVED, $edited->document_status);
        $this->assertNull($edited->reviewed_at);
        $this->assertSame('2027-01-20', $edited->requested_date->toDateString());
    }

    public function test_editing_voids_the_last_review_and_replaces_the_lines(): void
    {
        [$customer, $quote] = $this->customerWithQuote();
        $order = new ReviewSalesOrderAction($this->register($customer, $quote))->execute();

        $edited = new UpdateSalesOrderAction(
            $order,
            SalesOrderData::forUpdate(
                $order,
                $this->kanvasApp,
                static::$cachedUser,
                $this->company,
                ['lines' => [['description' => 'Pallet of rice', 'quantity' => 1, 'unit_price' => 20]]]
            )
        )->execute();

        $this->assertNull($edited->reviewed_at);
        $this->assertSame('20.0000', $edited->order_total);
        $this->assertCount(1, $edited->lines);
    }

    public function test_a_confirmed_order_cannot_be_edited(): void
    {
        $order = $this->confirmedOrder();

        $this->expectException(InvalidSalesOrderTransitionException::class);
        $this->expectExceptionMessage('received or returned');

        new UpdateSalesOrderAction(
            $order,
            SalesOrderData::forUpdate(
                $order,
                $this->kanvasApp,
                static::$cachedUser,
                $this->company,
                ['notes' => 'x']
            )
        )->execute();
    }

    public function test_cancelling_records_the_reason_and_a_second_cancel_changes_nothing(): void
    {
        $order = $this->confirmedOrder();

        $cancelled = new CancelSalesOrderAction($order, ' Customer withdrew ')->execute();
        $again = new CancelSalesOrderAction($cancelled, 'Another')->execute();

        $this->assertSame(SalesOrderStatusEnum::CANCELLED, $again->document_status);
        $this->assertSame('Customer withdrew', $again->cancel_reason);
    }

    public function test_a_shipped_order_cannot_be_cancelled(): void
    {
        $order = $this->confirmedOrder();
        $order->document_status = SalesOrderStatusEnum::SHIPPED;
        $order->save();

        $this->expectException(InvalidSalesOrderTransitionException::class);

        new CancelSalesOrderAction($order)->execute();
    }

    public function test_the_lifecycle_only_moves_forward(): void
    {
        $machine = new SalesOrderStateMachineService();

        $this->assertTrue($machine->canTransition(SalesOrderStatusEnum::RECEIVED, SalesOrderStatusEnum::CONFIRMED));
        $this->assertTrue($machine->canTransition(SalesOrderStatusEnum::RETURNED, SalesOrderStatusEnum::RECEIVED));
        $this->assertFalse($machine->canTransition(SalesOrderStatusEnum::RECEIVED, SalesOrderStatusEnum::ACCEPTED));
        $this->assertFalse($machine->canTransition(SalesOrderStatusEnum::CLOSED, SalesOrderStatusEnum::RECEIVED));
        $this->assertFalse($machine->canTransition(SalesOrderStatusEnum::CANCELLED, SalesOrderStatusEnum::RECEIVED));
    }

    /**
     * @return array{Organization, Quote}
     */
    private function customerWithQuote(): array
    {
        $customer = $this->seedTestOrganization('Test Customer');

        return [$customer, $this->createTestQuote($customer)];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function register(Organization $customer, Quote $quote, array $overrides = []): SalesOrder
    {
        return new RegisterCustomerPoAction(
            SalesOrderData::fromMultiple(
                $this->kanvasApp,
                static::$cachedUser,
                $this->company,
                $overrides + [
                    'customer_id' => $customer->getId(),
                    'po_source' => 'customer_po',
                    'quote_id' => $quote->getId(),
                    'customer_po_number' => 'PO-9001',
                    'customer_po_date' => '2026-11-20',
                    'currency' => 'USD',
                    'lines' => self::LINES,
                    'signature_checklist' => self::SIGNED,
                ]
            )
        )->execute();
    }

    private function confirmedOrder(): SalesOrder
    {
        [$customer, $quote] = $this->customerWithQuote();
        $order = new ReviewSalesOrderAction($this->register($customer, $quote))->execute();

        return new ConfirmSalesOrderAction($order, feasibilityConfirmed: true)->execute();
    }
}
