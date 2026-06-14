<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Exceptions\InvalidInvoiceTransitionException;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Services\InvoiceStateMachine;
use Kanvas\Scribe\Ledger\Actions\ReverseJournalEntryAction;
use Kanvas\Scribe\Ledger\Enums\JournalEntryStatusEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;

/**
 * Voids an issued or sent invoice by posting a mirror reversal JE via ReverseJournalEntryAction.
 *
 * Voiding a PAID invoice is intentionally not allowed (state machine rejects). For paid invoices, issue
 * a credit_note via IssueCreditNoteAction instead.
 *
 * @see plan §7.7 — Reversals preserve history
 * @see ReverseJournalEntryAction — shared mirror logic across all sub-ledger Void actions
 */
class VoidInvoiceAction
{
    public function __construct(
        public readonly Invoice $invoice,
        public readonly string $voidReasonCode,
        public readonly ?UserInterface $user = null,
        protected readonly InvoiceStateMachine $stateMachine = new InvoiceStateMachine(),
    ) {
    }

    public function execute(): Invoice
    {
        $this->stateMachine->assertTransition($this->invoice, InvoiceDocumentStatusEnum::VOIDED);

        $original = $this->findOriginalIssueJournalEntry($this->invoice);
        if ($original === null) {
            throw new InvalidInvoiceTransitionException(
                "Invoice {$this->invoice->id} has no posted Issue journal entry to reverse. "
                . 'A draft invoice should be soft-deleted, not voided.'
            );
        }

        return DB::connection('accounting')->transaction(function () use ($original): Invoice {
            $invoice = $this->invoice;

            new ReverseJournalEntryAction(
                original: $original,
                app: $invoice->app,
                company: $invoice->company,
                memo: "Invoice {$invoice->invoice_number} void — reverses JE {$original->je_number}",
                user: $this->user,
                sourceType: 'invoice',
                sourceId: $invoice->id,
            )->execute();

            $invoice->document_status = InvoiceDocumentStatusEnum::VOIDED;
            $invoice->collection_state = null;
            $invoice->voided_at = Carbon::now();
            $invoice->void_reason_code = $this->voidReasonCode;
            $invoice->save();

            return $invoice->refresh();
        });
    }

    private function findOriginalIssueJournalEntry(Invoice $invoice): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('apps_id', $invoice->apps_id)
            ->where('companies_id', $invoice->companies_id)
            ->where('source_type', 'invoice')
            ->where('source_id', $invoice->id)
            ->where('status', JournalEntryStatusEnum::POSTED->value)
            ->whereNull('is_reversal_of')
            ->orderBy('id')
            ->first();
    }
}
