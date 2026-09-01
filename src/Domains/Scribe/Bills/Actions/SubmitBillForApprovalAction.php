<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Approvals\Enums\ApprovalQueueStatusEnum;
use Kanvas\Scribe\Approvals\Models\ApprovalQueueItem;
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

        $this->openGenericApproval($bill);

        return $bill;
    }

    private function submit(): Bill
    {
        return DB::connection('accounting')->transaction(function (): Bill {
            $bill = $this->bill;

            $bill->document_status = BillDocumentStatusEnum::PENDING_APPROVAL;
            $bill->save();

            ApprovalQueueItem::create([
                'apps_id' => $bill->apps_id,
                'companies_id' => $bill->companies_id,
                'requested_by_users_id' => $this->user?->getId(),
                'action_type' => 'approve_bill',
                'target_type' => 'bill',
                'target_id' => $bill->id,
                'payload' => [
                    'total_native' => (float) $bill->total_native,
                    'currency' => $bill->currency,
                    'vendor_organization_id' => $bill->vendor_organization_id,
                ],
                'status' => ApprovalQueueStatusEnum::PENDING,
            ]);

            return $bill->refresh();
        });
    }

    /**
     * Dual-write while the generic approvals domain is adopted: the legacy accounting.approval_queue
     * row above stays authoritative for Apex/Arc, and this opens the matching approval_requests row
     * only where the tenant has a policy configured. Returns null — and changes nothing — when it has
     * none, so seeding a policy is what switches a tenant over, not deploying this.
     */
    private function openGenericApproval(Bill $bill): void
    {
        $bill->requestApproval(
            'approve_bill',
            payload: [
                'total_native' => (float) $bill->total_native,
                'currency' => $bill->currency,
                'vendor_organization_id' => $bill->vendor_organization_id,
            ],
            requestedBy: $this->user,
        );
    }
}
