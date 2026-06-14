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
use Kanvas\Scribe\Ledger\Actions\PostJournalEntryAction;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntryData;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntryLineData;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Kanvas\Scribe\Ledger\Enums\JournalEntryStatusEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Spatie\LaravelData\DataCollection;

/**
 * Voids an issued or sent invoice by posting a mirror JE (DR↔CR swap) that reverses the original Issue JE.
 *
 * Voiding a PAID invoice is intentionally not allowed (state machine rejects). For paid invoices, issue
 * a credit_note via IssueCreditNoteAction (Phase 2+) instead.
 *
 * Also updates the original journal_entry.status to 'reversed' on the original Issue JE row, sets is_reversal_of
 * on the new mirror JE. Preserves history per plan §7.7 — "Reversals preserve history".
 *
 * @see plan §7.7 — Reversals preserve history
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

        return DB::connection('accounting')->transaction(function () use ($original) {
            $invoice = $this->invoice;

            $reversalLines = $this->mirrorLines($original);

            $reversalJe = new PostJournalEntryAction(
                data: new JournalEntryData(
                    app: $invoice->app,
                    company: $invoice->company,
                    postedAt: Carbon::now(),
                    sourceType: 'invoice',
                    lines: new DataCollection(JournalEntryLineData::class, $reversalLines),
                    sourceId: $invoice->id,
                    memo: "Invoice {$invoice->invoice_number} void — reverses JE {$original->je_number}",
                    isAdjustment: true,
                    isReversalOf: $original->id,
                    source: 'kanvas',
                    origin: JournalEntryOriginEnum::KANVAS,
                ),
                postedByUser: $this->user,
            )->execute();

            // Mark the original JE as reversed
            $original->status = JournalEntryStatusEnum::REVERSED;
            $original->save();

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

    /**
     * @return array<int, JournalEntryLineData>
     */
    private function mirrorLines(JournalEntry $original): array
    {
        $original->load('lines');

        $mirrored = [];
        foreach ($original->lines as $i => $line) {
            $mirrored[] = new JournalEntryLineData(
                account_id: $line->account_id,
                debit_native: (float) $line->credit_native,    // swap
                credit_native: (float) $line->debit_native,
                debit_base: (float) $line->credit_base,
                credit_base: (float) $line->debit_base,
                currency: $line->currency,
                fx_rate_to_base: (float) $line->fx_rate_to_base,
                sort_order: $i,
                customer_billable_type: $line->customer_billable_type,
                customer_billable_id: $line->customer_billable_id,
                vendor_billable_type: $line->vendor_billable_type,
                vendor_billable_id: $line->vendor_billable_id,
                item_id: $line->item_id,
                class_id: $line->class_id,
                department_id: $line->department_id,
                memo: $line->memo ? "REVERSAL — {$line->memo}" : null,
                metadata: $line->metadata,
            );
        }

        return $mirrored;
    }
}
