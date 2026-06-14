<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesReceipts\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Ledger\Actions\ReverseJournalEntryAction;
use Kanvas\Scribe\Ledger\Services\JournalEntryLookupService;
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
        protected readonly JournalEntryLookupService $journalEntryLookup = new JournalEntryLookupService(),
    ) {
    }

    public function execute(): SalesReceipt
    {
        $this->stateMachine->assertTransition($this->salesReceipt, SalesReceiptStatusEnum::VOIDED);

        $original = $this->journalEntryLookup->findOriginalPosted(
            appsId: (int) $this->salesReceipt->apps_id,
            companiesId: (int) $this->salesReceipt->companies_id,
            sourceType: 'sales_receipt',
            sourceId: (int) $this->salesReceipt->id,
        );
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
}
