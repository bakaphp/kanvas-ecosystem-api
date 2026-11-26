<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\Actions;

use Baka\Support\Str;
use Kanvas\Scribe\Ledger\Actions\Concerns\LocksDocumentRow;
use Kanvas\Scribe\Purchasing\Enums\PurchaseOrderStatusEnum;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Scribe\Purchasing\Services\PurchaseOrderStateMachineService;

/**
 * The effect of a rejection: the order goes back to draft for correction, with the reason in its staff notes.
 */
class RejectPurchaseOrderAction
{
    use LocksDocumentRow;

    public function __construct(
        protected readonly PurchaseOrder $order,
        protected readonly ?string $reason = null,
        protected readonly PurchaseOrderStateMachineService $stateMachine = new PurchaseOrderStateMachineService(),
    ) {
    }

    public function execute(): PurchaseOrder
    {
        return $this->withRowLocked($this->order, function (): PurchaseOrder {
            if ($this->order->document_status === PurchaseOrderStatusEnum::DRAFT) {
                return $this->order;
            }

            $this->stateMachine->assertTransition($this->order, PurchaseOrderStatusEnum::DRAFT);

            $reason = Str::trimToNull($this->reason);
            $this->order->returnToDraft($reason === null ? 'Rejected.' : "Rejected: {$reason}");

            return $this->order;
        });
    }
}
