<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Ledger\Actions\Concerns\LocksDocumentRow;
use Kanvas\Scribe\SalesOrders\Credit\CustomerCreditService;
use Kanvas\Scribe\SalesOrders\Enums\SalesOrderStatusEnum;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use Kanvas\Scribe\SalesOrders\Services\SalesOrderStateMachineService;
use Kanvas\Scribe\SalesOrders\Services\SalesOrderValidityService;

/**
 * Gate 1: Commercial confirms the order and hands it to Operations. It needs a review with no open difference, a
 * valid customer commitment, Operations' word that the order is feasible, and credit: the customer is not on hold and
 * the order fits in what is left of their limit, when one is set.
 */
class ConfirmSalesOrderAction
{
    use LocksDocumentRow;

    public function __construct(
        protected readonly SalesOrder $order,
        protected readonly bool $feasibilityConfirmed,
        protected readonly SalesOrderStateMachineService $stateMachine = new SalesOrderStateMachineService(),
        protected readonly SalesOrderValidityService $validity = new SalesOrderValidityService(),
        protected readonly CustomerCreditService $credit = new CustomerCreditService(),
    ) {
    }

    public function execute(): SalesOrder
    {
        return $this->credit->oneDecisionAtATime(
            $this->order->customer,
            fn (): SalesOrder => $this->withRowLocked($this->order, fn (): SalesOrder => $this->confirm())
        );
    }

    private function confirm(): SalesOrder
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

        $this->credit->assertCanConfirm($this->order);

        $this->order->document_status = SalesOrderStatusEnum::CONFIRMED;
        $this->order->feasibility_confirmed_at = Carbon::now();
        $this->order->confirmed_at = Carbon::now();
        $this->order->saveOrFail();

        return $this->order;
    }
}
