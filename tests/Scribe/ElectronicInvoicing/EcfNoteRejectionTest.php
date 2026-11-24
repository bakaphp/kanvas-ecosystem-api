<?php

declare(strict_types=1);

namespace Tests\Scribe\ElectronicInvoicing;

use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Jobs\SubmitEcfJob;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\Actions\IssueCreditNoteAction;
use Kanvas\Scribe\Invoices\Actions\IssueDebitNoteAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\Enums\AllocationStatusEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceCollectionStateEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoicePaymentAllocation;
use Kanvas\Scribe\Ledger\Enums\JournalEntryStatusEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Spatie\LaravelData\DataCollection;
use Tests\Stubs\Scribe\FakeEcfProvider;

/**
 * A note DGII rejects never counted: its JE is mirrored, a credit note's allocation on the parent is
 * reversed and the parent gets its balance back (Track B left this as `action_required: reverse_credit_note`).
 */
class EcfNoteRejectionTest extends EcfCompletionTestCase
{
    public function test_a_rejected_credit_note_is_reversed_and_the_parent_it_settled_reopens(): void
    {
        $invoice = $this->acceptedInvoice();
        $creditNote = new IssueCreditNoteAction(
            parentInvoice: $invoice,
            data: $this->noteData(1000.0, 180.0),
            user: static::$cachedUser,
        )->execute();
        $this->assertSame(InvoiceDocumentStatusEnum::PAID, $invoice->refresh()->document_status);

        FakeEcfProvider::$submitStatus = EcfStatusEnum::REJECTED;
        FakeEcfProvider::$messages = ['Código de modificación inválido'];
        new SubmitEcfJob($this->kanvasApp, EcfCompliance::current($creditNote))->handle();

        $creditNote->refresh();
        $this->assertSame(InvoiceDocumentStatusEnum::VOIDED, $creditNote->document_status);
        $this->assertSame('ecf_rejected', $creditNote->void_reason_code);
        $this->assertTrue((bool) EcfCompliance::read($creditNote)['burned']);
        $this->assertArrayNotHasKey('action_required', EcfCompliance::read($creditNote));

        $original = JournalEntry::query()->where('source_type', 'credit_note')->where('source_id', $creditNote->getId())->whereNull('is_reversal_of')->sole();
        $this->assertSame(JournalEntryStatusEnum::REVERSED, $original->status);
        $this->assertTrue(JournalEntry::query()->where('is_reversal_of', $original->id)->exists());

        $allocation = InvoicePaymentAllocation::query()->where('source_id', $creditNote->getId())->sole();
        $this->assertSame(AllocationStatusEnum::REVERSED, $allocation->status);

        $invoice->refresh();
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $invoice->document_status);
        $this->assertSame(InvoiceCollectionStateEnum::CURRENT, $invoice->collection_state);
        $this->assertSame(1180.0, (float) $invoice->balance_due_native);
        $this->assertTrue($this->ledgerHas($creditNote, 'scribe.credit_note.voided'));
    }

    public function test_a_rejected_debit_note_is_voided_and_its_receivable_reversed(): void
    {
        $invoice = $this->acceptedInvoice();
        $debitNote = new IssueDebitNoteAction(
            parentInvoice: $invoice,
            data: $this->noteData(100.0, 18.0),
            user: static::$cachedUser,
        )->execute();

        FakeEcfProvider::$submitStatus = EcfStatusEnum::REJECTED;
        new SubmitEcfJob($this->kanvasApp, EcfCompliance::current($debitNote))->handle();

        $this->assertSame(InvoiceDocumentStatusEnum::VOIDED, $debitNote->refresh()->document_status);
        $original = JournalEntry::query()->where('source_type', 'debit_note')->where('source_id', $debitNote->getId())->whereNull('is_reversal_of')->sole();
        $this->assertSame(JournalEntryStatusEnum::REVERSED, $original->status);
        $this->assertSame(1180.0, (float) $invoice->refresh()->balance_due_native);
    }

    private function noteData(float $net, float $tax): InvoiceData
    {
        return new InvoiceData(
            app: $this->kanvasApp,
            company: $this->company,
            billable: null,
            lines: new DataCollection(InvoiceLineData::class, [
                new InvoiceLineData(
                    description: 'Ajuste',
                    quantity: 1,
                    unit_price_native: $net,
                    tax_rate: 18.0,
                    tax_amount_native: $tax,
                ),
            ]),
            currency: 'DOP',
            fx_rate_to_base: 1.0,
            notes: 'Ajuste de precio',
        );
    }

    protected function invoiceBalance(Invoice $invoice): float
    {
        return (float) $invoice->refresh()->balance_due_native;
    }
}
