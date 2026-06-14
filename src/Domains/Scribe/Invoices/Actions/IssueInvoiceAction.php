<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions;

use Baka\Contracts\BillableInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\DocumentSequences\Enums\DocumentTypeEnum as SequenceDocumentTypeEnum;
use Kanvas\Scribe\DocumentSequences\Services\DocumentNumberAllocator;
use Kanvas\Scribe\Invoices\Enums\InvoiceCollectionStateEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Services\InvoiceJournalEntryComposer;
use Kanvas\Scribe\Invoices\Services\InvoiceStateMachine;
use Kanvas\Scribe\Ledger\Actions\PostJournalEntryAction;

/**
 * Transitions a draft invoice into ISSUED state.
 *
 * Atomic side effects (all wrapped in the accounting-DB transaction):
 *   1. State-machine assert (draft → issued)
 *   2. Hydrate billable snapshot from the live Guild model (per plan §7.4 — frozen at issue)
 *   3. Allocate invoice_number via DocumentNumberAllocator (atomic SELECT … FOR UPDATE)
 *   4. Set issued_date / due_date (if not already), collection_state='current'
 *   5. Compose the JE via InvoiceJournalEntryComposer (DR AR / CR Revenue + CR Tax Payable)
 *   6. PostJournalEntryAction writes the JE (which gates the fiscal-period check + validator)
 *   7. Update invoice.document_status = ISSUED
 *
 * Idempotent: re-issuing an already-ISSUED invoice is a no-op (state machine allows status → status as identity).
 *
 * @see plan §11 Flow 1 — invoice issued worked example
 */
class IssueInvoiceAction
{
    public function __construct(
        public readonly Invoice $invoice,
        public readonly BillableInterface $billable,
        public readonly ?UserInterface $user = null,
        protected readonly InvoiceStateMachine $stateMachine = new InvoiceStateMachine(),
        protected readonly InvoiceJournalEntryComposer $composer = new InvoiceJournalEntryComposer(),
        protected readonly ?DocumentNumberAllocator $allocator = null,
    ) {
    }

    public function execute(): Invoice
    {
        $this->stateMachine->assertTransition($this->invoice, InvoiceDocumentStatusEnum::ISSUED);

        if ($this->invoice->document_status === InvoiceDocumentStatusEnum::ISSUED) {
            // Idempotent — already issued.
            return $this->invoice;
        }

        return DB::connection('accounting')->transaction(function () {
            $invoice = $this->invoice;

            $this->freezeBillableSnapshot($invoice, $this->billable);
            $this->setDates($invoice);
            $this->allocateInvoiceNumberIfMissing($invoice);

            $invoice->document_status = InvoiceDocumentStatusEnum::ISSUED;
            $invoice->collection_state = InvoiceCollectionStateEnum::CURRENT;
            $invoice->save();

            // Reload relations the composer needs (taxLines for jurisdiction-specific JE lines)
            $invoice->refresh();
            $invoice->load(['taxLines']);

            $jeData = $this->composer->composeIssue($invoice);

            new PostJournalEntryAction(
                data: $jeData,
                postedByUser: $this->user,
            )->execute();

            return $invoice->refresh();
        });
    }

    private function freezeBillableSnapshot(Invoice $invoice, BillableInterface $billable): void
    {
        $invoice->billable_type = $billable->getBillableType();
        $invoice->billable_id = $billable->getBillableId();
        $invoice->billable_display_name = $billable->getBillableDisplayName();
        $invoice->billable_legal_name = $billable->getBillableLegalName();
        $invoice->billable_tax_id = $billable->getBillableTaxId();
        $invoice->billable_email = $billable->getBillingEmail();
        $invoice->billing_address_snapshot = $billable->getBillingAddressArray();
    }

    private function setDates(Invoice $invoice): void
    {
        $today = Carbon::today();
        if ($invoice->issued_date === null) {
            $invoice->issued_date = $today;
        }

        if ($invoice->due_date === null) {
            $invoice->due_date = $invoice->issued_date->copy()->addDays((int) ($invoice->net_terms_days ?? 0));
        }
    }

    private function allocateInvoiceNumberIfMissing(Invoice $invoice): void
    {
        if ($invoice->invoice_number !== null && $invoice->invoice_number !== '') {
            return;
        }

        $allocator = $this->allocator ?? new DocumentNumberAllocator();
        $sequenceType = $invoice->isCreditNote()
            ? SequenceDocumentTypeEnum::CREDIT_NOTE
            : SequenceDocumentTypeEnum::INVOICE;

        $invoice->invoice_number = $allocator->allocate(
            $invoice->apps_id,
            $invoice->companies_id,
            $sequenceType,
            defaultPrefix: '',
        );
    }
}
