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
use Kanvas\Scribe\Invoices\Enums\InvoiceCollectionStateEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Exceptions\InvalidInvoiceTransitionException;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Services\InvoiceJournalEntryComposerService;
use Kanvas\Scribe\Ledger\Actions\PostJournalEntryAction;

/**
 * Issues a DRAFT debit note: numbers it (the next E33 on an e-CF company, `DBN-n` otherwise), posts the
 * receivable JE and opens its own balance due. Unlike a credit note it allocates nothing on the parent —
 * it is a second receivable the customer pays on its own.
 */
class IssueDraftDebitNoteAction
{
    use AllocatesInvoiceNumber;

    public function __construct(
        public readonly Invoice $debitNote,
        public readonly ?UserInterface $user = null,
        protected readonly InvoiceJournalEntryComposerService $composer = new InvoiceJournalEntryComposerService(),
        protected readonly ?DocumentNumberAllocatorService $allocator = null,
        protected readonly EcfIssuanceService $ecf = new EcfIssuanceService(),
    ) {
    }

    public function execute(): Invoice
    {
        if (! $this->debitNote->isDebitNote()) {
            throw new InvalidInvoiceTransitionException("Invoice {$this->debitNote->getId()} is not a debit note.");
        }

        if ($this->debitNote->document_status === InvoiceDocumentStatusEnum::ISSUED) {
            return $this->debitNote;
        }

        if ($this->debitNote->document_status !== InvoiceDocumentStatusEnum::DRAFT) {
            throw new InvalidInvoiceTransitionException(
                "Debit note {$this->debitNote->getId()} is {$this->debitNote->document_status->value}; only a draft can be issued."
            );
        }

        $parent = $this->debitNote->parentInvoice;
        self::assertParentDebitable($parent);

        $issuesEcf = $this->ecf->issuesEcf($this->debitNote);

        if ($issuesEcf) {
            $this->ecf->assertMayIssue($this->debitNote);
        }

        $debitNote = DB::connection('accounting')->transaction(function () use ($parent, $issuesEcf): Invoice {
            $debitNote = $this->debitNote;
            $debitNote->issued_date ??= Carbon::today();
            $debitNote->due_date ??= $debitNote->issued_date->copy()->addDays((int) $debitNote->net_terms_days);
            $debitNote->document_status = InvoiceDocumentStatusEnum::ISSUED;
            $debitNote->collection_state = InvoiceCollectionStateEnum::CURRENT;

            if ($issuesEcf) {
                $this->ecf->assignNumber($debitNote);
            } else {
                $this->allocateNumberIfMissing(
                    $debitNote,
                    SequenceDocumentTypeEnum::DEBIT_NOTE,
                    'DBN-',
                    $this->allocator
                );
            }

            $debitNote->save();

            $debitNote->refresh();
            $debitNote->load(['lines', 'taxLines']);

            new PostJournalEntryAction(
                data: $this->composer->composeDebitNote($debitNote),
                postedByUser: $this->user,
            )->execute();

            $debitNote->emitLedgerEvent(
                eventType: 'scribe.debit_note.issued',
                payload: [
                    'debit_note_number' => $debitNote->invoice_number,
                    'encf' => EcfCompliance::number($debitNote),
                    'parent_invoice_id' => $parent?->id,
                    'parent_invoice_number' => $parent?->invoice_number,
                    'currency' => $debitNote->currency,
                    'total_native' => (float) $debitNote->total_native,
                    'total_base' => (float) $debitNote->total_base,
                    'due_date' => $debitNote->due_date?->toDateString(),
                ],
            );

            return $debitNote->refresh();
        });

        if ($issuesEcf) {
            $this->ecf->dispatchSubmission($debitNote);
        }

        return $debitNote;
    }

    public static function assertParentDebitable(?Invoice $parent): void
    {
        if ($parent === null) {
            throw new InvalidInvoiceTransitionException('A debit note must reference the invoice it adds to.');
        }

        if ($parent->document_type->isNote()) {
            throw new InvalidInvoiceTransitionException(
                "A debit note can only be issued against an invoice, not a {$parent->document_type->label()} (id={$parent->id})."
            );
        }

        if (! $parent->document_status->isEffective()) {
            throw new InvalidInvoiceTransitionException(
                "Cannot issue a debit note on invoice {$parent->id} in status '{$parent->document_status->value}'. "
                . 'The invoice must be issued, sent, or paid.'
            );
        }
    }
}
