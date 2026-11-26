<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\Actions;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Ledger\Actions\Concerns\LocksDocumentRow;
use Kanvas\Scribe\Purchasing\Enums\PurchaseOrderStatusEnum;
use Kanvas\Scribe\Purchasing\Exceptions\InvalidPurchaseOrderTransitionException;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Scribe\Purchasing\Services\PurchaseOrderStateMachineService;

/**
 * Cancels a purchase order the company issued, as long as no goods have arrived against it.
 */
class CancelPurchaseOrderAction
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
            if (! $this->order->isIssuedByCompany()) {
                throw InvalidPurchaseOrderTransitionException::notIssuedByCompany($this->order, 'cancelled');
            }

            if ($this->order->document_status === PurchaseOrderStatusEnum::CANCELLED) {
                return $this->order;
            }

            $this->stateMachine->assertTransition($this->order, PurchaseOrderStatusEnum::CANCELLED);

            $this->order->document_status = PurchaseOrderStatusEnum::CANCELLED;
            $this->order->cancelled_at = Carbon::now();
            $this->order->cancel_reason = Str::trimToNull($this->reason);
            $this->order->saveOrFail();

            return $this->order;
        });
    }
}
