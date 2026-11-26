<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Actions;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\SalesOrders\Enums\SalesOrderStatusEnum;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use Kanvas\Scribe\SalesOrders\Services\SalesOrderStateMachineService;

/**
 * Cancels a sales order that has not shipped.
 */
class CancelSalesOrderAction
{
    public function __construct(
        protected readonly SalesOrder $order,
        protected readonly ?string $reason = null,
        protected readonly SalesOrderStateMachineService $stateMachine = new SalesOrderStateMachineService(),
    ) {
    }

    public function execute(): SalesOrder
    {
        if ($this->order->document_status === SalesOrderStatusEnum::CANCELLED) {
            return $this->order;
        }

        $this->stateMachine->assertTransition($this->order, SalesOrderStatusEnum::CANCELLED);

        $this->order->document_status = SalesOrderStatusEnum::CANCELLED;
        $this->order->cancelled_at = Carbon::now();
        $this->order->cancel_reason = Str::trimToNull($this->reason);
        $this->order->saveOrFail();

        return $this->order;
    }
}
