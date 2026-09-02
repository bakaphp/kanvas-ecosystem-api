<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Approvals\Actions\RequestApprovalAction;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Services\BillStateMachineService;

/**
 * Moves a DRAFT bill into PENDING_APPROVAL and drops an item in the approval queue. No JE posts yet —
 * the books aren't touched until a human approves. This is the entry point for the Kanvas-first
 * AP-bill agent: it proposes a coded bill, this parks it for sign-off.
 *
 * @see ApproveBillAction — sign-off → RECEIVED (posts the AP JE)
 * @see RejectBillAction — send it back to DRAFT for correction
 */
class SubmitBillForApprovalAction
{
    public function __construct(
        public readonly Bill $bill,
        public readonly ?UserInterface $user = null,
        protected readonly BillStateMachineService $stateMachine = new BillStateMachineService(),
    ) {
    }

    public function execute(): Bill
    {
        $this->stateMachine->assertTransition($this->bill, BillDocumentStatusEnum::PENDING_APPROVAL);

        if ($this->bill->document_status === BillDocumentStatusEnum::PENDING_APPROVAL) {
            return $this->bill;
        }

        $bill = $this->submit();

        $this->openApproval($bill);

        return $bill;
    }

    private function submit(): Bill
    {
        return DB::connection('accounting')->transaction(function (): Bill {
            $bill = $this->bill;

            $bill->document_status = BillDocumentStatusEnum::PENDING_APPROVAL;
            $bill->save();

            return $bill->refresh();
        });
    }

    /**
     * One call, one decision: RequestApprovalAction opens a generic request when the tenant has a
     * policy and falls back to the legacy queue row when it does not. Never both.
     */
    private function openApproval(Bill $bill): void
    {
        new RequestApprovalAction(
            app: $bill->app,
            company: $bill->company,
            actionType: 'approve_bill',
            targetType: 'bill',
            targetId: $bill->getId(),
            requestedByUser: $this->user,
            payload: [
                'total_native' => (float) $bill->total_native,
                'currency' => $bill->currency,
                'vendor_organization_id' => $bill->vendor_organization_id,
            ],
            entity: $bill,
        )->execute();
    }
}
