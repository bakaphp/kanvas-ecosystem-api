<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\SalesOrders\Actions\Concerns\PersistsSalesOrderLines;
use Kanvas\Scribe\SalesOrders\DataTransferObject\SalesOrder as SalesOrderData;
use Kanvas\Scribe\SalesOrders\Enums\SalesOrderStatusEnum;
use Kanvas\Scribe\SalesOrders\Exceptions\InvalidSalesOrderTransitionException;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use Kanvas\Scribe\SalesOrders\Services\SalesOrderStateMachineService;

/**
 * Edits a sales order that has not been confirmed yet, or one Operations returned: editing a returned order puts it
 * back in front of Commercial. Any edit voids the last review, since what was reviewed has changed.
 */
class UpdateSalesOrderAction
{
    use PersistsSalesOrderLines;

    public function __construct(
        protected readonly SalesOrder $order,
        protected readonly SalesOrderData $data,
        protected readonly SalesOrderStateMachineService $stateMachine = new SalesOrderStateMachineService(),
    ) {
    }

    public function execute(): SalesOrder
    {
        $editable = [SalesOrderStatusEnum::RECEIVED, SalesOrderStatusEnum::RETURNED];

        if (! in_array($this->order->document_status, $editable, true)) {
            throw new InvalidSalesOrderTransitionException(
                "Sales order {$this->order->order_number} is {$this->order->document_status->value}: only an order that is received or returned can be edited."
            );
        }

        $this->assertLines($this->data);

        return DB::connection('accounting')->transaction(function (): SalesOrder {
            if ($this->order->document_status === SalesOrderStatusEnum::RETURNED) {
                $this->stateMachine->assertTransition($this->order, SalesOrderStatusEnum::RECEIVED);
                $this->order->document_status = SalesOrderStatusEnum::RECEIVED;
            }

            $this->order->customer_po_number = $this->data->customer_po_number;
            $this->order->customer_po_date = $this->data->customer_po_date;
            $this->order->signature_checklist = $this->data->signature_checklist;
            $this->order->currency = $this->data->currency;
            $this->order->incoterm = $this->data->incoterm;
            $this->order->consignee = $this->data->consignee;
            $this->order->payment_terms_days = $this->data->payment_terms_days;
            $this->order->requested_date = $this->data->requested_date;
            $this->order->notes = $this->data->notes;
            $this->order->review = null;
            $this->order->reviewed_at = null;

            $this->persistLines($this->order, $this->data);

            return $this->order->fresh(['lines']);
        });
    }
}
