<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\SalesOrders\Enums\SalesOrderStatusEnum;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use Kanvas\Scribe\SalesOrders\Services\SalesOrderStateMachineService;

/**
 * Gate 2: Operations accepts a confirmed order with firm departure and arrival dates.
 */
class AcceptSalesOrderAction
{
    public function __construct(
        protected readonly SalesOrder $order,
        protected readonly Carbon $etd,
        protected readonly Carbon $eta,
        protected readonly SalesOrderStateMachineService $stateMachine = new SalesOrderStateMachineService(),
    ) {
    }

    public function execute(): SalesOrder
    {
        $this->stateMachine->assertTransition($this->order, SalesOrderStatusEnum::ACCEPTED);

        if ($this->eta->lt($this->etd)) {
            throw new ValidationException('The arrival date cannot be before the departure date.');
        }

        $this->order->document_status = SalesOrderStatusEnum::ACCEPTED;
        $this->order->etd = $this->etd;
        $this->order->eta = $this->eta;
        $this->order->accepted_at = Carbon::now();
        $this->order->saveOrFail();

        return $this->order;
    }
}
