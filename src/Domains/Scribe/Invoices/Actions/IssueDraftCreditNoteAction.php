<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\DocumentSequences\Enums\DocumentTypeEnum as SequenceDocumentTypeEnum;
use Kanvas\Scribe\DocumentSequences\Services\DocumentNumberAllocatorService;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfIssuanceService;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\Actions\Concerns\AllocatesInvoiceNumber;
use Kanvas\Scribe\Invoices\Enums\AllocationSourceTypeEnum;
use Kanvas\Scribe\Invoices\Enums\AllocationStatusEnum;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Exceptions\InvalidInvoiceTransitionException;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoicePaymentAllocation;
use Kanvas\Scribe\Invoices\Services\InvoiceJournalEntryComposerService;
use Kanvas\Scribe\Ledger\Actions\PostJournalEntryAction;

/**
 * Issues a credit note that already exists as a DRAFT: numbers it (the next E34 for an e-CF company,
 * `CRN-n` otherwise, or the caller's own number), posts its JE, and applies it to the parent invoice.
 *
 * IssueCreditNoteAction runs it right after creating the row; the approval handler runs it on a draft an
 * agent parked for sign-off.
 */
class IssueDraftCreditNoteAction
{
    use AllocatesInvoiceNumber;

    public function __construct(
        public readonly Invoice $creditNote,
        public readonly ?UserInterface $user = null,
        protected readonly InvoiceJournalEntryComposerService $composer = new InvoiceJournalEntryComposerService(),
        protected readonly ?DocumentNumberAllocatorService $allocator = null,
        protected readonly EcfIssuanceService $ecf = new EcfIssuanceService(),
    ) {
    }

    public function execute(): Invoice
    {
        if (! $this->creditNote->isCreditNote()) {
            throw new InvalidInvoiceTransitionException("Invoice {$this->creditNote->getId()} is not a credit note.");
        }

        if ($this->creditNote->document_status === InvoiceDocumentStatusEnum::ISSUED) {
            return $this->creditNote;
        }

        if ($this->creditNote->document_status !== InvoiceDocumentStatusEnum::DRAFT) {
            throw new InvalidInvoiceTransitionException(
                "Credit note {$this->creditNote->getId()} is {$this->creditNote->document_status->value}; only a draft can be issued."
            );
        }

        $parent = $this->creditNote->parentInvoice;
        self::assertParentCreditable($parent);

        $issuesEcf = $this->ecf->issuesEcf($this->creditNote);

        if ($issuesEcf) {
            $this->ecf->assertMayIssue($this->creditNote);
        }

        $creditNote = DB::connection('accounting')->transaction(function () use ($parent, $issuesEcf): Invoice {
            $creditNote = $this->creditNote;
            $creditNote->issued_date ??= Carbon::today();
            $creditNote->document_status = InvoiceDocumentStatusEnum::ISSUED;

            if ($issuesEcf) {
                $this->ecf->assignNumber($creditNote);
            } else {
                $this->allocateNumberIfMissing(
                    $creditNote,
                    SequenceDocumentTypeEnum::CREDIT_NOTE,
                    'CRN-',
                    $this->allocator
                );
            }

            $creditNote->save();

            $creditNote->refresh();
            $creditNote->load(['lines', 'taxLines']);

            new PostJournalEntryAction(
                data: $this->composer->composeCreditNote($creditNote),
                postedByUser: $this->user,
            )->execute();

            if ($parent !== null) {
                $this->writeAllocationOnParent($parent, $creditNote);

                new MarkInvoicePaidAction(
                    invoice: $parent,
                    user: $this->user,
                )->execute();
            }

            $creditNote->emitLedgerEvent(
                eventType: 'scribe.credit_note.issued',
                payload: [
                    'credit_note_number' => $creditNote->invoice_number,
                    'encf' => EcfCompliance::number($creditNote),
                    'parent_invoice_id' => $parent?->id,
                    'parent_invoice_number' => $parent?->invoice_number,
                    'currency' => $creditNote->currency,
                    'total_native' => (float) $creditNote->total_native,
                    'total_base' => (float) $creditNote->total_base,
                ],
            );

            return $creditNote->refresh();
        });

        if ($issuesEcf) {
            $this->ecf->dispatchSubmission($creditNote);
        }

        return $creditNote;
    }

    public static function assertParentCreditable(?Invoice $parent): void
    {
        if ($parent === null) {
            return;
        }

        if ($parent->document_type === DocumentTypeEnum::CREDIT_NOTE) {
            throw new InvalidInvoiceTransitionException(
                "Cannot issue a credit note against another credit note (parent id={$parent->id})."
            );
        }

        if (! $parent->document_status->isEffective()) {
            throw new InvalidInvoiceTransitionException(
                "Cannot credit invoice {$parent->id} in status '{$parent->document_status->value}'. "
                . 'Parent must be issued, sent, or paid.'
            );
        }
    }

    private function writeAllocationOnParent(Invoice $parent, Invoice $creditNote): void
    {
        $allocation = new InvoicePaymentAllocation();
        $allocation->apps_id = $parent->apps_id;
        $allocation->companies_id = $parent->companies_id;
        $allocation->invoice_id = $parent->id;
        $allocation->payment_id = null;
        $allocation->source_type = AllocationSourceTypeEnum::CREDIT_NOTE->value;
        $allocation->source_id = $creditNote->id;
        $allocation->status = AllocationStatusEnum::ACTIVE->value;
        $allocation->amount_native = (float) $creditNote->total_native;
        $allocation->amount_base = (float) $creditNote->total_base;
        $allocation->currency = $creditNote->currency;
        $allocation->fx_rate_to_base = (float) $creditNote->fx_rate_to_base;
        $allocation->fx_rate_at = $creditNote->fx_rate_at;
        $allocation->allocated_at = Carbon::now();
        $allocation->allocated_by_users_id = $this->user?->getId();
        $allocation->source = 'kanvas';
        $allocation->save();
    }
}
