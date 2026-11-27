<?php

declare(strict_types=1);

namespace Tests\Scribe\Ledger;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Banking\Actions\CreateBankAccountAction;
use Kanvas\Scribe\Banking\DataTransferObject\BankAccount as BankAccountData;
use Kanvas\Scribe\Bills\Actions\AllocateBillPaymentAction;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\Actions\ReceiveBillAction;
use Kanvas\Scribe\Bills\Actions\UpdateBillAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\Exceptions\InvalidBillTransitionException;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Invoices\Actions\AllocateInvoicePaymentAction;
use Kanvas\Scribe\Invoices\Actions\CreateInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\MarkInvoicePaidAction;
use Kanvas\Scribe\Invoices\Actions\UpdateInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\Exceptions\InvalidInvoiceTransitionException;
use Kanvas\Scribe\Invoices\Models\InvoicePaymentAllocation;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Exceptions\InvalidJournalEntryLineException;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\Payments\Actions\CreateScribePaymentAction;
use Kanvas\Scribe\Payments\Enums\PaymentDirectionEnum;
use Kanvas\Scribe\Payments\Enums\PaymentMethodEnum;
use Kanvas\Scribe\Payments\Enums\PaymentStatusEnum;
use Kanvas\Scribe\Payments\Models\Payment;
use RuntimeException;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

class LedgerIntegrityTest extends ScribeTestCase
{
    public function test_a_line_cannot_post_to_another_companys_account(): void
    {
        $foreign = $this->expenseAccount()->replicate();
        $foreign->companies_id = $this->company->getId() + 987654;
        $foreign->uuid = (string) Str::uuid();
        $foreign->save();
        $bill = $this->draftBill($foreign->getId());

        try {
            new ReceiveBillAction($bill, $bill->vendor)->execute();
            $this->fail('A bill line naming another company\'s account should not post.');
        } catch (InvalidJournalEntryLineException $e) {
            $this->assertStringContainsString('not in this company\'s chart of accounts', $e->getMessage());
        }

        $this->assertSame(0, $foreign->lines()->count());
    }

    public function test_an_account_kept_in_another_currency_only_takes_lines_in_it(): void
    {
        $account = $this->expenseAccount();
        $account->currency = 'EUR';
        $account->save();
        $bill = $this->draftBill($account->getId());

        $this->expectException(InvalidJournalEntryLineException::class);
        $this->expectExceptionMessage('kept in EUR');

        new ReceiveBillAction($bill, $bill->vendor)->execute();
    }

    public function test_a_copy_loaded_earlier_cannot_pay_an_invoice_past_its_balance(): void
    {
        $invoice = $this->issueTestInvoice($this->seedTestOrganization('Overlapping Payments Customer'), 100);
        $stale = $invoice->fresh();
        new AllocateInvoicePaymentAction($invoice, 80)->execute();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeds remaining balance');

        new AllocateInvoicePaymentAction($stale, 40)->execute();
    }

    public function test_a_copy_loaded_earlier_cannot_pay_a_bill_past_its_balance(): void
    {
        $bill = $this->draftBill();
        $bill = new ReceiveBillAction($bill, $bill->vendor)->execute();
        $stale = $bill->fresh();
        new AllocateBillPaymentAction($bill, 80)->execute();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeds remaining balance');

        new AllocateBillPaymentAction($stale, 40)->execute();
    }

    public function test_one_payment_never_pays_more_than_it_is_worth(): void
    {
        $customer = $this->seedTestOrganization('Payment Reuse Customer');
        $first = $this->issueTestInvoice($customer, 100);
        $second = $this->issueTestInvoice($customer, 100);
        $payment = $this->receivedPayment(100);
        new AllocateInvoicePaymentAction($first, 100, payment: $payment)->execute();

        try {
            new AllocateInvoicePaymentAction($second, 100, payment: $payment)->execute();
            $this->fail('A payment of 100 should not pay 200 of invoices.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not fit', $e->getMessage());
        }

        $allocated = InvoicePaymentAllocation::query()->where('payment_id', $payment->getId())->sum('amount_native');
        $this->assertSame(100.0, (float) $allocated);
    }

    public function test_a_payment_of_another_company_is_refused(): void
    {
        $invoice = $this->issueTestInvoice($this->seedTestOrganization('Foreign Payment Customer'), 100);
        $payment = $this->receivedPayment(100);
        $payment->companies_id = $this->company->getId() + 987654;
        $payment->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not belong to this company');

        new AllocateInvoicePaymentAction($invoice, 100, payment: $payment)->execute();
    }

    public function test_a_payment_that_was_reversed_settles_nothing(): void
    {
        $invoice = $this->issueTestInvoice($this->seedTestOrganization('Reversed Payment Customer'), 100);
        $payment = $this->receivedPayment(100);
        $payment->status = PaymentStatusEnum::REVERSED;
        $payment->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is reversed');

        new AllocateInvoicePaymentAction($invoice, 100, payment: $payment)->execute();
    }

    public function test_money_received_does_not_settle_a_bill_and_money_paid_out_does_not_settle_an_invoice(): void
    {
        $invoice = $this->issueTestInvoice($this->seedTestOrganization('Wrong Direction Customer'), 100);
        $bill = $this->draftBill();
        $bill = new ReceiveBillAction($bill, $bill->vendor)->execute();
        $received = $this->receivedPayment(100);
        $paidOut = $this->receivedPayment(100);
        $paidOut->direction = PaymentDirectionEnum::OUTBOUND;
        $paidOut->save();

        try {
            new AllocateBillPaymentAction($bill, 100, payment: $received)->execute();
            $this->fail('Money received should not settle a bill.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('is inbound', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is outbound');

        new AllocateInvoicePaymentAction($invoice, 100, payment: $paidOut)->execute();
    }

    public function test_a_copy_loaded_while_a_bill_was_a_draft_cannot_edit_it_once_received(): void
    {
        $bill = $this->draftBill();
        $stale = $bill->fresh();
        new ReceiveBillAction($bill, $bill->vendor)->execute();

        $this->expectException(InvalidBillTransitionException::class);

        new UpdateBillAction(
            $stale,
            BillData::fromMultiple($this->kanvasApp, $this->company, [
                'vendor_organization_id' => $bill->vendor_organization_id,
                'bill_number' => $bill->bill_number,
                'currency' => 'USD',
                'lines' => [[
                    'description' => 'Travel',
                    'quantity' => 20,
                    'unit_price_native' => 10,
                    'expense_account_id' => $this->expenseAccount()->getId(),
                ]],
            ])
        )->execute();
    }

    public function test_a_copy_loaded_while_an_invoice_was_a_draft_cannot_edit_it_once_issued(): void
    {
        $customer = $this->seedTestOrganization('Edited After Issue Customer');
        $draft = new CreateInvoiceAction($this->invoiceOf($customer, 100))->execute();
        $stale = $draft->fresh();
        new IssueInvoiceAction($draft, $customer)->execute();

        try {
            new UpdateInvoiceAction($stale, $this->invoiceOf($customer, 200))->execute();
            $this->fail('An issued invoice should not be edited from an older copy.');
        } catch (InvalidInvoiceTransitionException) {
            $this->assertSame(100.0, (float) $draft->fresh()->total_native);
        }
    }

    public function test_issuing_from_a_copy_loaded_earlier_posts_the_invoice_once(): void
    {
        $customer = $this->seedTestOrganization('Issued Twice Customer');
        $draft = new CreateInvoiceAction($this->invoiceOf($customer, 100))->execute();
        $stale = $draft->fresh();
        $issued = new IssueInvoiceAction($draft, $customer)->execute();

        $again = new IssueInvoiceAction($stale, $customer)->execute();

        $this->assertSame($issued->invoice_number, $again->invoice_number);
        $this->assertSame(1, $this->entriesOf('invoice', $draft->getId()));
    }

    public function test_receiving_from_a_copy_loaded_earlier_posts_the_bill_once(): void
    {
        $bill = $this->draftBill();
        $stale = $bill->fresh();
        new ReceiveBillAction($bill, $bill->vendor)->execute();

        new ReceiveBillAction($stale, $stale->vendor)->execute();

        $this->assertSame(1, $this->entriesOf('bill', $bill->getId()));
    }

    public function test_marking_paid_locks_the_invoice_before_it_sums_and_works_on_the_row_not_the_copy(): void
    {
        $invoice = $this->issueTestInvoice($this->seedTestOrganization('Recompute Customer'), 100);
        new AllocateInvoicePaymentAction(invoice: $invoice, amountNative: 40.0)->execute();
        $copy = $invoice->fresh();
        $copy->forceFill(['paid_base' => 0, 'balance_due_base' => 100])->syncOriginal();
        $statements = [];
        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        new MarkInvoicePaidAction($copy)->execute();

        $locked = array_find_key(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'from `invoices`') && str_contains($sql, 'for update')
        );
        $summed = array_find_key(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'from `invoice_payment_allocations`')
        );
        $this->assertNotNull($locked);
        $this->assertLessThan($summed, $locked);
        $this->assertSame(60.0, (float) $invoice->fresh()->balance_due_base);
    }

    public function test_a_payment_posts_to_the_ledger_account_of_the_bank_it_names(): void
    {
        $second = Account::getById($this->accountIdBySubType(AccountSubTypeEnum::CASH_CHECKING))->replicate();
        $second->uuid = (string) Str::uuid();
        $second->account_number = 'CHK2-' . uniqid();
        $second->name = 'Second checking account';
        $second->save();
        $bank = new CreateBankAccountAction(
            new BankAccountData(
                app: $this->kanvasApp,
                company: $this->company,
                account_name: 'Second bank',
                gl_account_id: $second->getId(),
                currency: 'USD',
            )
        )->execute();
        $invoice = $this->issueTestInvoice($this->seedTestOrganization('Second Bank Customer'), 100);

        $allocation = new AllocateInvoicePaymentAction($invoice, 100, bankAccountId: $bank->getId())->execute();

        $entry = JournalEntry::query()
            ->where('source_type', 'payment')
            ->where('source_id', $allocation->payment_id)
            ->firstOrFail();
        $this->assertTrue($entry->lines->contains('account_id', $second->getId()));
    }

    private function invoiceOf(Organization $customer, float $amount): InvoiceData
    {
        return new InvoiceData(
            app: $this->kanvasApp,
            company: $this->company,
            billable: $customer,
            lines: new DataCollection(InvoiceLineData::class, [
                new InvoiceLineData(description: 'Service', quantity: 1, unit_price_native: $amount),
            ]),
            currency: 'USD',
            fx_rate_to_base: 1,
        );
    }

    private function expenseAccount(): Account
    {
        return Account::getById($this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS));
    }

    private function draftBill(?int $expenseAccountId = null): Bill
    {
        return new CreateBillAction(
            BillData::fromMultiple($this->kanvasApp, $this->company, [
                'vendor_organization_id' => $this->seedTestOrganization('Integrity Vendor')->getId(),
                'currency' => 'USD',
                'bill_number' => 'INT-' . uniqid(),
                'bill_date' => Carbon::today()->toDateString(),
                'lines' => [[
                    'description' => 'Travel',
                    'quantity' => 10,
                    'unit_price_native' => 10,
                    'expense_account_id' => $expenseAccountId ?? $this->expenseAccount()->getId(),
                ]],
            ])
        )->execute();
    }

    private function receivedPayment(float $amount): Payment
    {
        return new CreateScribePaymentAction(
            app: $this->kanvasApp,
            company: $this->company,
            amountNative: $amount,
            currency: 'USD',
            direction: PaymentDirectionEnum::INBOUND,
            method: PaymentMethodEnum::WIRE,
        )->execute();
    }

    private function entriesOf(string $sourceType, int $sourceId): int
    {
        return JournalEntry::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->count();
    }
}
