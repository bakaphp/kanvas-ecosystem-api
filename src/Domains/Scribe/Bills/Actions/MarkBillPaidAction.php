<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Models\BillPaymentAllocation;
use Kanvas\Scribe\Bills\Services\BillStateMachineService;
use Kanvas\Scribe\Ledger\Services\PayableMarkAsPaidExecutorService;

/**
 * Recomputes bill paid/balance from active allocations and flips status to PAID when the balance
 * hits zero. Workflow is shared with the AR side — see `PayableMarkAsPaidExecutorService`.
 *
 * Idempotent on already-paid bills. Does NOT post the Cash JE — that's the responsibility of
 * AllocateBillPaymentAction when the allocation row is created.
 */
class MarkBillPaidAction
{
    public function __construct(
        public readonly Bill $bill,
        public readonly ?UserInterface $user = null,
        protected readonly BillStateMachineService $stateMachine = new BillStateMachineService(),
        protected readonly PayableMarkAsPaidExecutorService $executor = new PayableMarkAsPaidExecutorService(),
    ) {
    }

    public function execute(): Bill
    {
        /** @var Bill $bill */
        $bill = $this->executor->execute(
            entity: $this->bill,
            paidStatusEnum: BillDocumentStatusEnum::PAID,
            canTransitionToPaid: fn ($current) => $this->stateMachine->canTransition(
                $current,
                BillDocumentStatusEnum::PAID,
            ),
            allocationModelClass: BillPaymentAllocation::class,
            allocationFkColumn: 'bill_id',
            ledgerEventName: 'scribe.bill.paid',
            extraEventPayload: ['bill_number' => $this->bill->bill_number],
        );

        return $bill;
    }
}
