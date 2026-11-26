<?php

declare(strict_types=1);

namespace Tests\Scribe\SalesOrders;

use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\FxRates\Models\FxRate;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\SalesOrders\Actions\CheckShippingDocumentsAction;
use Kanvas\Scribe\SalesOrders\Actions\ConfirmSalesOrderAction;
use Kanvas\Scribe\SalesOrders\Actions\RecordShipmentAction;
use Kanvas\Scribe\SalesOrders\Actions\ReleaseDocumentsAction;
use Kanvas\Scribe\SalesOrders\Actions\SetCustomerCreditLimitAction;
use Kanvas\Scribe\SalesOrders\Credit\CustomerCreditService;
use Kanvas\Scribe\SalesOrders\Credit\CustomerCreditStatus;
use Kanvas\Scribe\SalesOrders\Enums\CreditConfigurationEnum;
use Kanvas\Scribe\SalesOrders\Enums\SalesOrderStatusEnum;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use Kanvas\Scribe\SalesOrders\Services\SalesOrderCollectionPlanService;
use PHPUnit\Framework\Attributes\Group;
use Tests\Scribe\ScribeTestCase;
use Tests\Traits\CreatesTestSalesOrder;

#[Group('serial')]
class CreditTest extends ScribeTestCase
{
    use CreatesTestSalesOrder;

    private mixed $originalHoldDays = null;

    protected function afterScribeSetUp(): void
    {
        // Settings write through Redis, which DatabaseTransactions never rolls back.
        $this->originalHoldDays = $this->company->get(CreditConfigurationEnum::CREDIT_HOLD_DAYS->value);
    }

    protected function tearDown(): void
    {
        $this->originalHoldDays === null
            ? $this->company->del(CreditConfigurationEnum::CREDIT_HOLD_DAYS->value)
            : $this->company->set(CreditConfigurationEnum::CREDIT_HOLD_DAYS->value, $this->originalHoldDays);

        parent::tearDown();
    }

    public function test_a_customer_without_a_limit_is_not_checked_against_one(): void
    {
        $customer = $this->seedTestOrganization('No Limit Customer');

        $status = $this->creditOf($customer);

        $this->assertFalse($status->hasLimit());
        $this->assertNull($status->available());
        $this->assertNull($status->openBalance);
    }

    public function test_an_order_inside_the_limit_is_confirmed(): void
    {
        $customer = $this->customerWithLimit(1000);
        $order = $this->createReviewedSalesOrder($customer);

        $confirmed = new ConfirmSalesOrderAction($order, feasibilityConfirmed: true)->execute();

        $this->assertSame(SalesOrderStatusEnum::CONFIRMED, $confirmed->document_status);
    }

    public function test_an_order_above_what_is_left_of_the_limit_is_refused(): void
    {
        $customer = $this->customerWithLimit(300);
        $order = $this->createReviewedSalesOrder($customer);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('is above the USD 300.00 still available');

        new ConfirmSalesOrderAction($order, feasibilityConfirmed: true)->execute();
    }

    public function test_orders_already_committed_use_up_the_limit(): void
    {
        $customer = $this->customerWithLimit(600);
        $first = $this->createReviewedSalesOrder($customer);
        $second = $this->createReviewedSalesOrder($customer);

        new ConfirmSalesOrderAction($first, feasibilityConfirmed: true)->execute();

        $this->assertSame(250.0, $this->creditOf($customer, $second)->available());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('still available');

        new ConfirmSalesOrderAction($second, feasibilityConfirmed: true)->execute();
    }

    public function test_open_invoices_use_up_the_limit(): void
    {
        $customer = $this->customerWithLimit(600);
        $this->issueTestInvoice($customer, 500.0, dueDate: Carbon::today()->addDays(30)->toDateString());

        $status = $this->creditOf($customer);

        $this->assertSame(500.0, $status->openBalance);
        $this->assertSame(100.0, $status->available());
        $this->assertSame(0, $status->overdueInvoices);
    }

    public function test_a_customer_with_an_invoice_overdue_past_the_hold_days_gets_no_new_order_confirmed(): void
    {
        $customer = $this->seedTestOrganization('Overdue Customer');
        $this->overdueInvoice($customer, termStartedDaysAgo: 140, overdueDays: 90);
        $order = $this->createReviewedSalesOrder($customer);

        $status = $this->creditOf($customer);
        $this->assertTrue($status->onHold);
        $this->assertSame(90, $status->oldestOverdueDays);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('90 days overdue: their new orders are on hold');

        new ConfirmSalesOrderAction($order, feasibilityConfirmed: true)->execute();
    }

    public function test_the_company_can_move_the_hold_threshold(): void
    {
        $customer = $this->seedTestOrganization('Patient Customer');
        $this->overdueInvoice($customer, termStartedDaysAgo: 140, overdueDays: 90);
        $this->company->set(CreditConfigurationEnum::CREDIT_HOLD_DAYS->value, '150');

        $this->assertFalse($this->creditOf($customer)->onHold);
    }

    public function test_a_hold_threshold_of_zero_holds_on_any_overdue_invoice_and_nobody_else(): void
    {
        $late = $this->seedTestOrganization('One Day Late Customer');
        $this->issueTestInvoice($late, 100.0, dueDate: Carbon::today()->subDay()->toDateString());
        $clean = $this->seedTestOrganization('Clean Customer');
        $this->company->set(CreditConfigurationEnum::CREDIT_HOLD_DAYS->value, '0');

        $this->assertTrue($this->creditOf($late)->onHold);
        $this->assertFalse($this->creditOf($clean)->onHold);
    }

    public function test_an_invoice_that_is_not_overdue_never_holds_the_customer(): void
    {
        $customer = $this->seedTestOrganization('Long Term Customer');
        $invoice = $this->issueTestInvoice($customer, 100.0, dueDate: Carbon::today()->addDays(10)->toDateString());
        $invoice->forceFill(['issued_date' => Carbon::today()->subDays(140)])->save();

        $this->assertFalse($this->creditOf($customer)->onHold);
    }

    public function test_the_hold_starts_on_the_day_the_collection_plan_names(): void
    {
        $customer = $this->seedTestOrganization('Plan Hold Customer');
        $order = $this->createShippedSalesOrder($customer);
        $plan = collect(new SalesOrderCollectionPlanService()->plan($order->shipment));
        $holdStep = $plan->firstWhere('owner', 'Gates 1 and 3');

        $this->assertSame(CustomerCreditService::DEFAULT_HOLD_DAYS, $holdStep['day']);

        Carbon::setTestNow(Carbon::parse($holdStep['date'])->subDay());
        $this->assertFalse($this->creditOf($customer)->onHold);

        Carbon::setTestNow(Carbon::parse($holdStep['date']));
        $this->assertTrue($this->creditOf($customer)->onHold);
    }

    public function test_a_long_payment_term_is_held_only_once_it_is_overdue(): void
    {
        $customer = $this->seedTestOrganization('Long Term Shipped Customer');
        $order = $this->createAcceptedSalesOrder($customer, 120);
        $order = new CheckShippingDocumentsAction(
            $order,
            $this->issueInvoiceWithLines($customer, [
                ['quantity' => 10, 'unit_price' => 20],
                ['quantity' => 5, 'unit_price' => 30],
            ]),
            [['quantity' => 10], ['quantity' => 5]],
            ['total_quantity' => 15]
        )->execute();
        $shipment = new RecordShipmentAction(
            order: $order,
            blNumber: 'BL-LONG-TERM',
            blDate: Carbon::parse('2026-12-10'),
            user: static::$cachedUser,
        )->execute();
        $holdStep = collect(new SalesOrderCollectionPlanService()->plan($shipment))->firstWhere('owner', 'Gates 1 and 3');

        $this->assertSame(121, $holdStep['day']);

        Carbon::setTestNow(Carbon::parse($holdStep['date'])->subDay());
        $this->assertFalse($this->creditOf($customer)->onHold);

        Carbon::setTestNow(Carbon::parse($holdStep['date']));
        $this->assertTrue($this->creditOf($customer)->onHold);
    }

    public function test_a_second_confirmation_for_the_customer_waits_for_the_first(): void
    {
        $customer = $this->customerWithLimit(600);
        $order = $this->createReviewedSalesOrder($customer);
        $credit = new CustomerCreditService(decisionWaitSeconds: 0);

        try {
            $credit->oneDecisionAtATime(
                $customer,
                fn () => new ConfirmSalesOrderAction($order, feasibilityConfirmed: true, credit: $credit)->execute()
            );
            $this->fail('A confirmation should not run while another one holds the customer.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('being confirmed right now', $e->getMessage());
        }

        $confirmed = new ConfirmSalesOrderAction($order, feasibilityConfirmed: true, credit: $credit)->execute();
        $this->assertSame(SalesOrderStatusEnum::CONFIRMED, $confirmed->document_status);
    }

    public function test_a_draft_invoice_does_not_take_the_order_out_of_the_exposure(): void
    {
        $customer = $this->customerWithLimit(600);
        $order = $this->createAcceptedSalesOrder($customer);
        $this->assertSame(250.0, $this->creditOf($customer)->available());

        new CheckShippingDocumentsAction(
            $order,
            $this->draftInvoiceFor($order),
            [['quantity' => 10], ['quantity' => 5]],
            ['total_quantity' => 15]
        )->execute();

        $this->assertSame(250.0, $this->creditOf($customer)->available());
    }

    public function test_another_companys_orders_are_not_counted_in_the_exposure(): void
    {
        $customer = $this->customerWithLimit(600);
        $this->foreignConfirmedOrder($customer);

        $status = $this->creditOf($customer);

        $this->assertSame(0.0, $status->committedOrders);
        $this->assertSame(600.0, $status->available());
    }

    public function test_a_limit_in_another_currency_is_converted_with_the_published_rate(): void
    {
        $customer = $this->customerWithLimit(600, 'ZZZ');
        FxRate::create([
            'apps_id' => $this->kanvasApp->getId(),
            'base_currency' => 'USD',
            'quote_currency' => 'ZZZ',
            'rate' => 2,
            'rate_date' => Carbon::today(),
            'source' => 'test',
        ]);
        $order = $this->createReviewedSalesOrder($customer);

        $this->assertSame(600.0, $this->creditOf($customer)->available());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('is above the ZZZ 600.00 still available');

        new ConfirmSalesOrderAction($order, feasibilityConfirmed: true)->execute();
    }

    public function test_without_a_published_rate_credit_is_not_guessed(): void
    {
        $customer = $this->customerWithLimit(5000, 'ZZY');
        $order = $this->createReviewedSalesOrder($customer);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no published rate for USD/ZZY');

        new ConfirmSalesOrderAction($order, feasibilityConfirmed: true)->execute();
    }

    public function test_a_limit_needs_a_currency_and_can_be_removed(): void
    {
        $customer = $this->customerWithLimit(100);

        try {
            new SetCustomerCreditLimitAction($customer, 100.0, 'US')->execute();
            $this->fail('A limit without a three-letter currency should be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('three-letter currency', $e->getMessage());
        }

        new SetCustomerCreditLimitAction($customer, null, null)->execute();

        $this->assertFalse($this->creditOf($customer)->hasLimit());
    }

    public function test_documents_are_released_to_a_customer_in_good_standing(): void
    {
        $order = $this->createShippedSalesOrder($this->seedTestOrganization('Good Standing Customer'));

        $first = new ReleaseDocumentsAction($order)->execute();
        $again = new ReleaseDocumentsAction($order->fresh())->execute();

        $this->assertNotNull($first->documents_released_at);
        $this->assertEquals($first->documents_released_at, $again->documents_released_at);
    }

    public function test_documents_are_held_back_from_a_customer_with_an_overdue_invoice(): void
    {
        $customer = $this->seedTestOrganization('Late Customer');
        $order = $this->createShippedSalesOrder($customer);
        $this->issueTestInvoice($customer, 100.0, dueDate: Carbon::today()->subDays(10)->toDateString());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('1 overdue invoice(s), the oldest 10 days overdue');

        new ReleaseDocumentsAction($order)->execute();
    }

    public function test_documents_are_held_back_from_a_customer_over_their_limit(): void
    {
        $customer = $this->customerWithLimit(400);
        $order = $this->createShippedSalesOrder($customer);
        $this->issueTestInvoice($customer, 300.0, dueDate: Carbon::today()->addDays(30)->toDateString());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('USD 250.00 over their credit limit');

        new ReleaseDocumentsAction($order)->execute();
    }

    public function test_an_order_that_has_not_shipped_has_no_documents_to_release(): void
    {
        $order = $this->createAcceptedSalesOrder($this->seedTestOrganization('Unshipped Customer'));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('has not shipped');

        new ReleaseDocumentsAction($order)->execute();
    }

    private function customerWithLimit(float $amount, string $currency = 'USD'): Organization
    {
        $customer = $this->seedTestOrganization('Limited Customer');
        new SetCustomerCreditLimitAction($customer, $amount, $currency)->execute();

        return $customer;
    }

    /**
     * The ledger only takes an issue date inside the open period, so the start of the term is moved back afterwards.
     */
    private function overdueInvoice(Organization $customer, int $termStartedDaysAgo, int $overdueDays): Invoice
    {
        $invoice = $this->issueTestInvoice(
            $customer,
            100.0,
            dueDate: Carbon::today()->subDays($overdueDays)->toDateString()
        );
        $invoice->forceFill(['issued_date' => Carbon::today()->subDays($termStartedDaysAgo)])->save();

        return $invoice;
    }

    private function foreignConfirmedOrder(Organization $customer): SalesOrder
    {
        return SalesOrder::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId() + 987654,
            'order_number' => 'SO-FOREIGN-' . uniqid(),
            'document_status' => SalesOrderStatusEnum::CONFIRMED,
            'po_source' => 'customer_po',
            'customer_organization_id' => $customer->getId(),
            'currency' => 'USD',
            'order_total' => 9999,
            'received_at' => Carbon::now(),
            'confirmed_at' => Carbon::now(),
        ]);
    }

    private function creditOf(Organization $customer, ?SalesOrder $excluding = null): CustomerCreditStatus
    {
        return new CustomerCreditService()->status($customer, $excluding);
    }
}
