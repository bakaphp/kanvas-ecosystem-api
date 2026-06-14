<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesReceipts\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Ledger\Actions\PostJournalEntryAction;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntryData;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntryLineData;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Kanvas\Scribe\Ledger\Enums\JournalEntryStatusEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\SalesReceipts\Enums\SalesReceiptStatusEnum;
use Kanvas\Scribe\SalesReceipts\Exceptions\InvalidSalesReceiptTransitionException;
use Kanvas\Scribe\SalesReceipts\Models\SalesReceipt;
use Kanvas\Scribe\SalesReceipts\Services\SalesReceiptStateMachine;
use Spatie\LaravelData\DataCollection;

/**
 * Voids a recorded sales receipt by posting a mirror (DR↔CR swap) JE that reverses the original Create JE.
 *
 * Parallel to VoidInvoiceAction — same pattern: original JE flagged status=reversed, new mirror JE has
 * is_reversal_of pointing at it, receipt flips to VOIDED.
 *
 * @see plan §7.7 — Reversals preserve history
 */
class VoidSalesReceiptAction
{
    public function __construct(
        public readonly SalesReceipt $salesReceipt,
        public readonly string $voidReasonCode,
        public readonly ?UserInterface $user = null,
        protected readonly SalesReceiptStateMachine $stateMachine = new SalesReceiptStateMachine(),
    ) {
    }

    public function execute(): SalesReceipt
    {
        $this->stateMachine->assertTransition($this->salesReceipt, SalesReceiptStatusEnum::VOIDED);

        $original = $this->findOriginalCreateJournalEntry($this->salesReceipt);
        if ($original === null) {
            throw new InvalidSalesReceiptTransitionException(
                "Sales receipt {$this->salesReceipt->id} has no posted Create JE to reverse."
            );
        }

        return DB::connection('accounting')->transaction(function () use ($original): SalesReceipt {
            $receipt = $this->salesReceipt;

            $reversalLines = $this->mirrorLines($original);

            new PostJournalEntryAction(
                data: new JournalEntryData(
                    app: $receipt->app,
                    company: $receipt->company,
                    postedAt: Carbon::now(),
                    sourceType: 'sales_receipt',
                    lines: new DataCollection(JournalEntryLineData::class, $reversalLines),
                    sourceId: $receipt->id,
                    memo: "Sales Receipt {$receipt->receipt_number} void — reverses JE {$original->je_number}",
                    isAdjustment: true,
                    isReversalOf: $original->id,
                    source: 'kanvas',
                    origin: JournalEntryOriginEnum::KANVAS,
                ),
                postedByUser: $this->user,
            )->execute();

            $original->status = JournalEntryStatusEnum::REVERSED;
            $original->save();

            $receipt->status = SalesReceiptStatusEnum::VOIDED;
            $receipt->voided_at = Carbon::now();
            $receipt->void_reason_code = $this->voidReasonCode;
            $receipt->save();

            return $receipt->refresh();
        });
    }

    private function findOriginalCreateJournalEntry(SalesReceipt $receipt): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('apps_id', $receipt->apps_id)
            ->where('companies_id', $receipt->companies_id)
            ->where('source_type', 'sales_receipt')
            ->where('source_id', $receipt->id)
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
                debit_native: (float) $line->credit_native,
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
