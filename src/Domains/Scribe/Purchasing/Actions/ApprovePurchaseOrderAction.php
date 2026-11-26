<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Purchasing\Enums\PurchaseOrderStatusEnum;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Scribe\Purchasing\Services\PurchaseOrderStateMachineService;

/**
 * The effect of an approval: the order goes from PENDING_APPROVAL to APPROVED. Who may approve is the approvals
 * engine's business; this only moves the order.
 */
class ApprovePurchaseOrderAction
{
    public function __construct(
        protected readonly PurchaseOrder $order,
        protected readonly PurchaseOrderStateMachineService $stateMachine = new PurchaseOrderStateMachineService(),
    ) {
    }

    public function execute(): PurchaseOrder
    {
        if ($this->order->document_status === PurchaseOrderStatusEnum::APPROVED) {
            return $this->order;
        }

        $this->stateMachine->assertTransition($this->order, PurchaseOrderStatusEnum::APPROVED);

        $this->order->document_status = PurchaseOrderStatusEnum::APPROVED;
        $this->order->approved_at = Carbon::now();
        $this->order->saveOrFail();

        return $this->order;
    }
}
