<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\SalesOrders\Enums\SalesOrderStatusEnum;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use Kanvas\Scribe\SalesOrders\Services\SalesOrderStateMachineService;
use Kanvas\Scribe\SalesOrders\Services\SalesOrderValidityService;

/**
 * Gate 1: Commercial confirms the order and hands it to Operations. It needs a review with no open difference, a
 * valid customer commitment, and Operations' word that the order is feasible.
 */
class ConfirmSalesOrderAction
{
    public function __construct(
        protected readonly SalesOrder $order,
        protected readonly bool $feasibilityConfirmed,
        protected readonly SalesOrderStateMachineService $stateMachine = new SalesOrderStateMachineService(),
        protected readonly SalesOrderValidityService $validity = new SalesOrderValidityService(),
    ) {
    }

    public function execute(): SalesOrder
    {
        $this->stateMachine->assertTransition($this->order, SalesOrderStatusEnum::CONFIRMED);

        if ($this->order->reviewed_at === null) {
            throw new ValidationException("Review sales order {$this->order->order_number} against its quote before confirming it.");
        }

        $open = $this->order->openDifferenceKeys();

        if ($open !== []) {
            throw new ValidationException(
                'Clarify every difference in writing before confirming. Still open: ' . implode(', ', $open) . '.'
            );
        }

        $missing = $this->validity->missing($this->order);

        if ($missing !== []) {
            throw new ValidationException(
                "The customer's commitment is not valid yet. Not confirmed: " . implode(', ', $missing) . '.'
            );
        }

        if (! $this->feasibilityConfirmed) {
            throw new ValidationException('Operations has to confirm the order is feasible before it is confirmed.');
        }

        $this->order->document_status = SalesOrderStatusEnum::CONFIRMED;
        $this->order->feasibility_confirmed_at = Carbon::now();
        $this->order->confirmed_at = Carbon::now();
        $this->order->saveOrFail();

        return $this->order;
    }
}
