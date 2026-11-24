<?php

declare(strict_types=1);

namespace Tests\Scribe\Invoices;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Invoices\Actions\IssueCreditNoteAction;
use Kanvas\Scribe\Invoices\Actions\IssueDebitNoteAction;
use Kanvas\Scribe\Invoices\Actions\VoidInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Exceptions\InvalidInvoiceTransitionException;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\Ledger\Models\JournalEntryLine;
use Kanvas\Scribe\Reports\Repositories\ArAgingRepository;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

/**
 * Debit notes on a company without e-CF: own DBN-n numbering, a receivable JE of their own, counted as AR.
 */
class DebitNoteTest extends ScribeTestCase
{
    public function test_a_debit_note_numbers_posts_a_receivable_and_counts_in_ar(): void
    {
        $customer = $this->seedTestOrganization('Debit Note Customer ' . uniqid());
        $invoice = $this->issueTestInvoice($customer, subtotal: 1000.0);
        $agingBefore = new ArAgingRepository()->generate($this->kanvasApp, $this->company, Carbon::parse('2026-06-20'))->grand_total;

        $debitNote = new IssueDebitNoteAction(
            parentInvoice: $invoice,
            data: $this->data(250.0),
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(DocumentTypeEnum::DEBIT_NOTE, $debitNote->document_type);
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $debitNote->document_status);
        $this->assertStringStartsWith('DBN-', (string) $debitNote->invoice_number);
        $this->assertSame(250.0, (float) $debitNote->balance_due_native);
        $this->assertSame($invoice->customer_organization_id, $debitNote->customer_organization_id);
        $this->assertSame($debitNote->getId(), $invoice->debitNotes()->sole()->getId());

        $je = JournalEntry::query()->where('source_type', 'debit_note')->where('source_id', $debitNote->getId())->sole();
        $arDebit = JournalEntryLine::query()
            ->where('journal_entry_id', $je->id)
            ->where('account_id', $this->accountIdBySubType(AccountSubTypeEnum::ACCOUNTS_RECEIVABLE))
            ->sum('debit_native');
        $this->assertEqualsWithDelta(250.0, (float) $arDebit, 0.001);

        $agingAfter = new ArAgingRepository()->generate($this->kanvasApp, $this->company, Carbon::parse('2026-06-20'))->grand_total;
        $this->assertEqualsWithDelta(250.0, $agingAfter - $agingBefore, 0.001);
    }

    public function test_a_debit_note_needs_an_issued_invoice_parent(): void
    {
        $customer = $this->seedTestOrganization('Debit Note Parent ' . uniqid());
        $invoice = $this->issueTestInvoice($customer, subtotal: 500.0);
        $creditNote = new IssueCreditNoteAction(
            parentInvoice: $invoice,
            data: $this->data(100.0),
            user: static::$cachedUser,
        )->execute();

        $this->expectException(InvalidInvoiceTransitionException::class);

        new IssueDebitNoteAction(parentInvoice: $creditNote, data: $this->data(10.0), user: static::$cachedUser)->execute();
    }

    public function test_voiding_a_debit_note_reverses_its_own_entry_not_the_invoice(): void
    {
        $customer = $this->seedTestOrganization('Debit Note Void ' . uniqid());
        $invoice = $this->issueTestInvoice($customer, subtotal: 800.0);
        $debitNote = new IssueDebitNoteAction(parentInvoice: $invoice, data: $this->data(80.0), user: static::$cachedUser)->execute();

        new VoidInvoiceAction(invoice: $debitNote, voidReasonCode: 'requested_by_user', user: static::$cachedUser)->execute();

        $this->assertSame(InvoiceDocumentStatusEnum::VOIDED, $debitNote->refresh()->document_status);
        $this->assertTrue(JournalEntry::query()->where('source_type', 'debit_note')->where('source_id', $debitNote->getId())->whereNotNull('is_reversal_of')->exists());
        $this->assertFalse(JournalEntry::query()->where('source_type', 'invoice')->where('source_id', $invoice->getId())->whereNotNull('is_reversal_of')->exists());
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $invoice->refresh()->document_status);
    }

    public function test_voiding_a_credit_note_gives_the_parent_its_balance_back(): void
    {
        $customer = $this->seedTestOrganization('Credit Note Void ' . uniqid());
        $invoice = $this->issueTestInvoice($customer, subtotal: 300.0);
        $creditNote = new IssueCreditNoteAction(parentInvoice: $invoice, data: $this->data(300.0), user: static::$cachedUser)->execute();
        $this->assertSame(InvoiceDocumentStatusEnum::PAID, $invoice->refresh()->document_status);

        new VoidInvoiceAction(invoice: $creditNote, voidReasonCode: 'requested_by_user', user: static::$cachedUser)->execute();

        $invoice->refresh();
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $invoice->document_status);
        $this->assertSame(300.0, (float) $invoice->balance_due_native);
    }

    private function data(float $amount): InvoiceData
    {
        return new InvoiceData(
            app: $this->kanvasApp,
            company: $this->company,
            billable: null,
            lines: new DataCollection(InvoiceLineData::class, [
                new InvoiceLineData(description: 'Adjustment', quantity: 1, unit_price_native: $amount),
            ]),
            currency: 'USD',
            fx_rate_to_base: 1.0,
        );
    }
}
