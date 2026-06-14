<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesReceipts\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Ledger\Actions\ReverseJournalEntryAction;
use Kanvas\Scribe\Ledger\Enums\JournalEntryStatusEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\SalesReceipts\Enums\SalesReceiptStatusEnum;
use Kanvas\Scribe\SalesReceipts\Exceptions\InvalidSalesReceiptTransitionException;
use Kanvas\Scribe\SalesReceipts\Models\SalesReceipt;
use Kanvas\Scribe\SalesReceipts\Services\SalesReceiptStateMachineService;

/**
 * Voids a recorded sales receipt by posting a mirror reversal JE via ReverseJournalEntryAction.
 *
 * @see plan §7.7 — Reversals preserve history
 */
class VoidSalesReceiptAction
{
    public function __construct(
        public readonly SalesReceipt $salesReceipt,
        public readonly string $voidReasonCode,
        public readonly ?UserInterface $user = null,
        protected readonly SalesReceiptStateMachineService $stateMachine = new SalesReceiptStateMachineService(),
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

            new ReverseJournalEntryAction(
                original: $original,
                app: $receipt->app,
                company: $receipt->company,
                memo: "Sales Receipt {$receipt->receipt_number} void — reverses JE {$original->je_number}",
                user: $this->user,
                sourceType: 'sales_receipt',
                sourceId: $receipt->id,
            )->execute();

            $receipt->status = SalesReceiptStatusEnum::VOIDED;
            $receipt->voided_at = Carbon::now();
            $receipt->void_reason_code = $this->voidReasonCode;
            $receipt->save();

            $receipt->emitLedgerEvent(
                eventType: 'scribe.sales_receipt.voided',
                payload: [
                    'receipt_number' => $receipt->receipt_number,
                    'void_reason_code' => $this->voidReasonCode,
                    'reversed_je_id' => $original->id,
                ],
            );

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
}
