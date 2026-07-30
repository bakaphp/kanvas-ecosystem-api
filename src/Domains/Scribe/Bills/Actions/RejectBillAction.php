<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Approvals\Enums\ApprovalQueueStatusEnum;
use Kanvas\Scribe\Approvals\Models\ApprovalQueueItem;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Services\BillStateMachineService;
use Kanvas\Workflow\Enums\WorkflowEnum;

class RejectBillAction
{
    public function __construct(
        public readonly Bill $bill,
        public readonly UserInterface $rejector,
        public readonly ?string $reason = null,
        protected readonly BillStateMachineService $stateMachine = new BillStateMachineService(),
    ) {
    }

    public function execute(): Bill
    {
        $this->stateMachine->assertTransition($this->bill, BillDocumentStatusEnum::DRAFT);

        if ($this->bill->document_status === BillDocumentStatusEnum::DRAFT) {
            return $this->bill;
        }

        $bill = DB::connection('accounting')->transaction(function (): Bill {
            $bill = $this->bill;

            $bill->document_status = BillDocumentStatusEnum::DRAFT;
            $bill->save();

            ApprovalQueueItem::query()
                ->where('apps_id', $bill->apps_id)
                ->where('companies_id', $bill->companies_id)
                ->where('action_type', 'approve_bill')
                ->where('target_type', 'bill')
                ->where('target_id', $bill->id)
                ->where('status', ApprovalQueueStatusEnum::PENDING->value)
                ->update([
                    'status' => ApprovalQueueStatusEnum::REJECTED->value,
                    'approved_by_users_id' => $this->rejector->getId(),
                    'approved_at' => Carbon::now(),
                    'reason' => $this->reason,
                ]);

            return $bill->refresh();
        });

        // Same generic event as approval, to = draft. The ERP-push rule filters on to = received, so
        // a rejection never triggers a push — it just lets rules react to the send-back.
        $bill->fireWorkflow(
            WorkflowEnum::STATUS_TRANSITION->value,
            params: [
                'app' => $bill->app,
                'entity' => 'bill',
                'from' => BillDocumentStatusEnum::PENDING_APPROVAL->value,
                'to' => BillDocumentStatusEnum::DRAFT->value,
            ],
        );

        return $bill;
    }
}
