<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Actions;

use Baka\Contracts\PayeeInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Approvals\Enums\ApprovalQueueStatusEnum;
use Kanvas\Scribe\Approvals\Models\ApprovalQueueItem;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Workflow\Enums\WorkflowEnum;

class ApproveBillAction
{
    public function __construct(
        public readonly Bill $bill,
        public readonly PayeeInterface $vendor,
        public readonly UserInterface $approver,
    ) {
    }

    public function execute(): Bill
    {
        if ($this->bill->document_status === BillDocumentStatusEnum::RECEIVED) {
            return $this->bill;
        }

        $bill = DB::connection('accounting')->transaction(function (): Bill {
            $bill = new ReceiveBillAction(
                $this->bill,
                $this->vendor,
                $this->approver,
            )->execute();

            ApprovalQueueItem::query()
                ->where('apps_id', $bill->apps_id)
                ->where('companies_id', $bill->companies_id)
                ->where('action_type', 'approve_bill')
                ->where('target_type', 'bill')
                ->where('target_id', $bill->id)
                ->where('status', ApprovalQueueStatusEnum::PENDING->value)
                ->update([
                    'status' => ApprovalQueueStatusEnum::APPROVED->value,
                    'approved_by_users_id' => $this->approver->getId(),
                    'approved_at' => Carbon::now(),
                ]);

            return $bill;
        });

        // Generic event so a workflow rule routes the approved bill to whichever ERP-push activity
        // it's wired to — the approval never hardcodes a connector.
        $bill->fireWorkflow(
            WorkflowEnum::STATUS_TRANSITION->value,
            params: [
                'app' => $bill->app,
                'entity' => 'bill',
                'from' => BillDocumentStatusEnum::PENDING_APPROVAL->value,
                'to' => BillDocumentStatusEnum::RECEIVED->value,
            ],
        );

        return $bill;
    }
}
