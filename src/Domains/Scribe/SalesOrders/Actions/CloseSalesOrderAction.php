<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Ledger\Actions\Concerns\LocksDocumentRow;
use Kanvas\Scribe\SalesOrders\Enums\SalesOrderStatusEnum;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use Kanvas\Scribe\SalesOrders\Services\SalesOrderStateMachineService;

/**
 * Closes a delivered order once its invoice is paid.
 */
class CloseSalesOrderAction
{
    use LocksDocumentRow;

    public function __construct(
        protected readonly SalesOrder $order,
        protected readonly SalesOrderStateMachineService $stateMachine = new SalesOrderStateMachineService(),
    ) {
    }

    public function execute(): SalesOrder
    {
        return $this->withRowLocked($this->order, function (): SalesOrder {
            $this->stateMachine->assertTransition($this->order, SalesOrderStatusEnum::CLOSED);

            if ($this->order->invoice?->document_status !== InvoiceDocumentStatusEnum::PAID) {
                throw new ValidationException(
                    "Sales order {$this->order->order_number} cannot be closed until its invoice is paid."
                );
            }

            $this->order->document_status = SalesOrderStatusEnum::CLOSED;
            $this->order->saveOrFail();

            return $this->order;
        });
    }
}
