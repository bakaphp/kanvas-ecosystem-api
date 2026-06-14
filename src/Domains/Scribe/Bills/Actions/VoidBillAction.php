<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Exceptions\InvalidBillTransitionException;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Services\BillStateMachineService;
use Kanvas\Scribe\Ledger\Actions\ReverseJournalEntryAction;
use Kanvas\Scribe\Ledger\Enums\JournalEntryStatusEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;

/**
 * Voids a received Bill by posting a mirror reversal JE via ReverseJournalEntryAction.
 *
 * Voiding a PAID bill is intentionally not allowed (state machine rejects). For paid bills, issue
 * a vendor_credit (deferred future PR).
 *
 * Parallel to VoidInvoiceAction / VoidSalesReceiptAction — shares ReverseJournalEntryAction.
 */
class VoidBillAction
{
    public function __construct(
        public readonly Bill $bill,
        public readonly string $voidReasonCode,
        public readonly ?UserInterface $user = null,
        protected readonly BillStateMachineService $stateMachine = new BillStateMachineService(),
    ) {
    }

    public function execute(): Bill
    {
        $this->stateMachine->assertTransition($this->bill, BillDocumentStatusEnum::VOIDED);

        $original = $this->findOriginalReceiveJournalEntry($this->bill);
        if ($original === null) {
            throw new InvalidBillTransitionException(
                "Bill {$this->bill->id} has no posted Receive journal entry to reverse. "
                . 'A draft bill should be soft-deleted, not voided.'
            );
        }

        return DB::connection('accounting')->transaction(function () use ($original): Bill {
            $bill = $this->bill;

            new ReverseJournalEntryAction(
                original: $original,
                app: $bill->app,
                company: $bill->company,
                memo: "Bill {$bill->bill_number} void — reverses JE {$original->je_number}",
                user: $this->user,
                sourceType: 'bill',
                sourceId: $bill->id,
            )->execute();

            $bill->document_status = BillDocumentStatusEnum::VOIDED;
            $bill->collection_state = null;
            $bill->voided_at = Carbon::now();
            $bill->void_reason_code = $this->voidReasonCode;
            $bill->save();

            $bill->emitLedgerEvent(
                eventType: 'scribe.bill.voided',
                payload: [
                    'bill_number' => $bill->bill_number,
                    'void_reason_code' => $this->voidReasonCode,
                    'reversed_je_id' => $original->id,
                ],
            );

            return $bill->refresh();
        });
    }

    private function findOriginalReceiveJournalEntry(Bill $bill): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('apps_id', $bill->apps_id)
            ->where('companies_id', $bill->companies_id)
            ->where('source_type', 'bill')
            ->where('source_id', $bill->id)
            ->where('status', JournalEntryStatusEnum::POSTED->value)
            ->whereNull('is_reversal_of')
            ->orderBy('id')
            ->first();
    }
}
