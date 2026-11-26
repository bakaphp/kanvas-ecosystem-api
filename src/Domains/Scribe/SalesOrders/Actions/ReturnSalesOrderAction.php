<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Actions;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\SalesOrders\Enums\SalesOrderStatusEnum;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use Kanvas\Scribe\SalesOrders\Services\SalesOrderStateMachineService;

/**
 * Gate 2: Operations cannot meet the order and returns it to Commercial, who renegotiates with the customer before
 * confirming again.
 */
class ReturnSalesOrderAction
{
    public function __construct(
        protected readonly SalesOrder $order,
        protected readonly string $reason,
        protected readonly SalesOrderStateMachineService $stateMachine = new SalesOrderStateMachineService(),
    ) {
    }

    public function execute(): SalesOrder
    {
        $this->stateMachine->assertTransition($this->order, SalesOrderStatusEnum::RETURNED);

        $reason = Str::trimToNull($this->reason)
            ?? throw new ValidationException('Say why Operations cannot accept the order.');

        $this->order->document_status = SalesOrderStatusEnum::RETURNED;
        $this->order->return_reason = $reason;
        $this->order->returned_at = Carbon::now();
        $this->order->saveOrFail();

        return $this->order;
    }
}
