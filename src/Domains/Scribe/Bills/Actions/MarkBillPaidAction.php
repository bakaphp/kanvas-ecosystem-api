<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Models\BillPaymentAllocation;
use Kanvas\Scribe\Bills\Services\BillStateMachineService;
use Kanvas\Scribe\Ledger\Services\PaymentAllocationSumService;

/**
 * Recomputes bill.paid_native / balance_due_native from active allocations and flips status to PAID
 * when the balance hits zero. Pure state-recompute — does NOT post a JE itself. The DR AP / CR Cash JE
 * is posted by the AllocatePaymentAction (or its equivalent) when the allocation row is created.
 *
 * Idempotent: re-running on an already-paid bill is a no-op.
 */
class MarkBillPaidAction
{
    public function __construct(
        public readonly Bill $bill,
        public readonly ?UserInterface $user = null,
        protected readonly BillStateMachineService $stateMachine = new BillStateMachineService(),
        protected readonly PaymentAllocationSumService $allocationSummer = new PaymentAllocationSumService(),
    ) {
    }

    public function execute(): Bill
    {
        return DB::connection('accounting')->transaction(function (): Bill {
            $bill = $this->bill;
            $statusBefore = $bill->document_status;

            [$paidNative, $paidBase] = $this->allocationSummer->sumActive(
                allocationModelClass: BillPaymentAllocation::class,
                fkColumn: 'bill_id',
                entityId: (int) $bill->id,
            );

            $bill->paid_native = $paidNative;
            $bill->paid_base = $paidBase;
            $bill->balance_due_native = max(0.0, (float) $bill->total_native - $paidNative);
            $bill->balance_due_base = max(0.0, (float) $bill->total_base - $paidBase);

            if ($bill->balance_due_native <= 0 && ! $bill->document_status->isTerminal()) {
                if ($this->stateMachine->canTransition($bill->document_status, BillDocumentStatusEnum::PAID)) {
                    $bill->document_status = BillDocumentStatusEnum::PAID;
                    $bill->collection_state = null;
                }
            }

            $bill->save();

            if ($statusBefore !== BillDocumentStatusEnum::PAID
                && $bill->document_status === BillDocumentStatusEnum::PAID) {
                $bill->emitLedgerEvent(
                    eventType: 'scribe.bill.paid',
                    payload: [
                        'bill_number' => $bill->bill_number,
                        'paid_native' => $paidNative,
                        'paid_base' => $paidBase,
                        'currency' => $bill->currency,
                    ],
                );
            }

            return $bill->refresh();
        });
    }
}
