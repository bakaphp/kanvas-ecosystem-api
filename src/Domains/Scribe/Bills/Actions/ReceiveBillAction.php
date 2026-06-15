<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Actions;

use Baka\Contracts\PayeeInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Bills\Enums\BillCollectionStateEnum;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Services\BillJournalEntryComposerService;
use Kanvas\Scribe\Bills\Services\BillStateMachineService;
use Kanvas\Scribe\DocumentSequences\Enums\DocumentTypeEnum as SequenceDocumentTypeEnum;
use Kanvas\Scribe\DocumentSequences\Services\DocumentNumberAllocatorService;
use Kanvas\Scribe\Ledger\Actions\PostJournalEntryAction;

/**
 * Transitions a draft Bill → RECEIVED.
 *
 * Atomic side effects (one accounting-DB transaction):
 *   1. State machine assert (draft → received)
 *   2. Vendor snapshot frozen from PayeeInterface
 *   3. Bill number allocated via DocumentNumberAllocatorService (if not already provided by the vendor)
 *   4. document_status = RECEIVED, collection_state = CURRENT, received_date stamped
 *   5. AP JE posted via BillJournalEntryComposerService (DR Expense + DR Input Tax / CR AP)
 *   6. scribe.bill.received ledger event emitted with bill_number + vendor + total
 *
 * Idempotent: re-running on already-RECEIVED is a no-op (state machine allows X → X).
 */
class ReceiveBillAction
{
    public function __construct(
        public readonly Bill $bill,
        public readonly PayeeInterface $vendor,
        public readonly ?UserInterface $user = null,
        protected readonly BillStateMachineService $stateMachine = new BillStateMachineService(),
        protected readonly BillJournalEntryComposerService $composer = new BillJournalEntryComposerService(),
        protected readonly ?DocumentNumberAllocatorService $allocator = null,
    ) {
    }

    public function execute(): Bill
    {
        $this->stateMachine->assertTransition($this->bill, BillDocumentStatusEnum::RECEIVED);

        if ($this->bill->document_status === BillDocumentStatusEnum::RECEIVED) {
            return $this->bill;
        }

        return DB::connection('accounting')->transaction(function (): Bill {
            $bill = $this->bill;

            $this->freezeVendorSnapshot($bill, $this->vendor);
            $this->allocateBillNumberIfMissing($bill);
            $this->setDates($bill);

            $bill->document_status = BillDocumentStatusEnum::RECEIVED;
            $bill->collection_state = BillCollectionStateEnum::CURRENT;
            $bill->save();

            $bill->refresh();
            $bill->load(['lines', 'taxLines']);

            $jeData = $this->composer->composeReceive($bill);
            new PostJournalEntryAction(
                data: $jeData,
                postedByUser: $this->user,
            )->execute();

            $bill->emitLedgerEvent(
                eventType: 'scribe.bill.received',
                payload: [
                    'bill_number' => $bill->bill_number,
                                        'vendor_id' => $bill->vendor_organization_id,
                    'vendor_display_name' => $bill->vendor_display_name,
                    'currency' => $bill->currency,
                    'total_native' => (float) $bill->total_native,
                    'total_base' => (float) $bill->total_base,
                    'due_date' => $bill->due_date?->toDateString(),
                ],
            );

            return $bill->refresh();
        });
    }

    private function freezeVendorSnapshot(Bill $bill, PayeeInterface $vendor): void
    {
        $bill->vendor_organization_id = $vendor->getPayeeId();
        $bill->vendor_display_name = $vendor->getPayeeDisplayName();
        $bill->vendor_legal_name = $vendor->getPayeeLegalName();
        $bill->vendor_tax_id = $vendor->getPayeeTaxId();
        $bill->vendor_email = $vendor->getPayeeEmail();
    }

    private function allocateBillNumberIfMissing(Bill $bill): void
    {
        if ($bill->bill_number !== null && $bill->bill_number !== '') {
            return;
        }

        $allocator = $this->allocator ?? new DocumentNumberAllocatorService();
        $bill->bill_number = $allocator->allocate(
            $bill->apps_id,
            $bill->companies_id,
            SequenceDocumentTypeEnum::BILL,
            defaultPrefix: 'BILL-',
        );
    }

    private function setDates(Bill $bill): void
    {
        $today = Carbon::today();
        if ($bill->received_date === null) {
            $bill->received_date = $today;
        }
        if ($bill->bill_date === null) {
            $bill->bill_date = $bill->received_date;
        }
        if ($bill->due_date === null) {
            $bill->due_date = $bill->bill_date->copy()->addDays((int) ($bill->net_terms_days ?? 0));
        }
    }
}
