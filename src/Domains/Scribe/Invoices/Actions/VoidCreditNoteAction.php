<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Invoices\Enums\AllocationSourceTypeEnum;
use Kanvas\Scribe\Invoices\Enums\AllocationStatusEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceCollectionStateEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Enums\ReversalReasonCodeEnum;
use Kanvas\Scribe\Invoices\Exceptions\InvalidInvoiceTransitionException;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoicePaymentAllocation;
use Kanvas\Scribe\Ledger\Actions\ReverseJournalEntryAction;
use Kanvas\Scribe\Ledger\Services\JournalEntryLookupService;

/**
 * Takes a credit note back off the books: mirrors its JE, flips the allocation it made on the parent to
 * `reversed`, and gives the parent back the balance it had before. VoidInvoiceAction hands credit notes
 * here, which is also how a credit note DGII rejected (its E34 never counted) is reversed.
 *
 * A parent the credit note had settled is PAID, a terminal status the paid-recompute never leaves, so it
 * is reopened here before the recompute runs.
 */
class VoidCreditNoteAction
{
    public function __construct(
        public readonly Invoice $creditNote,
        public readonly string $voidReasonCode,
        public readonly ?UserInterface $user = null,
        protected readonly JournalEntryLookupService $journalEntryLookup = new JournalEntryLookupService(),
    ) {
    }

    public function execute(): Invoice
    {
        $creditNote = $this->creditNote;

        if (! $creditNote->isCreditNote()) {
            throw new InvalidInvoiceTransitionException("Invoice {$creditNote->getId()} is not a credit note.");
        }

        if ($creditNote->document_status === InvoiceDocumentStatusEnum::VOIDED) {
            return $creditNote;
        }

        $original = $this->journalEntryLookup->findOriginalPosted(
            appsId: (int) $creditNote->apps_id,
            companiesId: (int) $creditNote->companies_id,
            sourceType: $creditNote->document_type->journalSourceType(),
            sourceId: (int) $creditNote->getId(),
        ) ?? throw new InvalidInvoiceTransitionException(
            "Credit note {$creditNote->getId()} has no posted journal entry to reverse."
        );

        return DB::connection('accounting')->transaction(function () use ($creditNote, $original): Invoice {
            new ReverseJournalEntryAction(
                original: $original,
                app: $creditNote->app,
                company: $creditNote->company,
                memo: "Credit Note {$creditNote->invoice_number} void — reverses JE {$original->je_number}",
                user: $this->user,
                sourceType: $creditNote->document_type->journalSourceType(),
                sourceId: $creditNote->getId(),
            )->execute();

            $parent = $creditNote->parentInvoice;

            if ($parent !== null) {
                $this->reverseAllocations($creditNote);
                $this->reopenParent($parent);
            }

            $creditNote->document_status = InvoiceDocumentStatusEnum::VOIDED;
            $creditNote->voided_at = Carbon::now();
            $creditNote->void_reason_code = $this->voidReasonCode;
            $creditNote->save();

            $creditNote->emitLedgerEvent(
                eventType: 'scribe.credit_note.voided',
                payload: [
                    'credit_note_number' => $creditNote->invoice_number,
                    'parent_invoice_id' => $parent?->getId(),
                    'void_reason_code' => $this->voidReasonCode,
                    'reversed_je_id' => $original->id,
                ],
            );

            return $creditNote->refresh();
        });
    }

    private function reverseAllocations(Invoice $creditNote): void
    {
        InvoicePaymentAllocation::query()
            ->where('source_type', AllocationSourceTypeEnum::CREDIT_NOTE->value)
            ->where('source_id', $creditNote->getId())
            ->where('status', AllocationStatusEnum::ACTIVE->value)
            ->get()
            ->each(function (InvoicePaymentAllocation $allocation): void {
                $allocation->status = AllocationStatusEnum::REVERSED;
                $allocation->reversed_at = Carbon::now();
                $allocation->reversed_by_users_id = $this->user?->getId();
                $allocation->reversal_reason_code = ReversalReasonCodeEnum::ADMINISTRATIVE->value;
                $allocation->reversal_reason = "Credit note voided: {$this->voidReasonCode}";
                $allocation->save();
            });
    }

    private function reopenParent(Invoice $parent): void
    {
        if ($parent->document_status === InvoiceDocumentStatusEnum::PAID) {
            $parent->document_status = $parent->sent_at !== null
                ? InvoiceDocumentStatusEnum::SENT
                : InvoiceDocumentStatusEnum::ISSUED;
            $parent->collection_state = InvoiceCollectionStateEnum::CURRENT;
            $parent->paid_at = null;
            $parent->save();
        }

        new MarkInvoicePaidAction(invoice: $parent, user: $this->user)->execute();
    }
}
