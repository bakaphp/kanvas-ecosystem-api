<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Actions;

use Kanvas\Scribe\Ledger\Actions\Concerns\LocksDocumentRow;
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
    use LocksDocumentRow;
    use PersistsSalesOrderLines;

    public function __construct(
        protected readonly SalesOrder $order,
        protected readonly SalesOrderData $data,
        protected readonly SalesOrderStateMachineService $stateMachine = new SalesOrderStateMachineService(),
    ) {
    }

    public function execute(): SalesOrder
    {
        $this->assertLines($this->data);

        return $this->withRowLocked($this->order, function (): SalesOrder {
            $order = $this->order;
            $editable = [SalesOrderStatusEnum::RECEIVED, SalesOrderStatusEnum::RETURNED];

            if (! in_array($order->document_status, $editable, true)) {
                throw new InvalidSalesOrderTransitionException(
                    "Sales order {$order->order_number} is {$order->document_status->value}: "
                    . 'only an order that is received or returned can be edited.'
                );
            }

            if ($order->document_status === SalesOrderStatusEnum::RETURNED) {
                $this->stateMachine->assertTransition($order, SalesOrderStatusEnum::RECEIVED);
                $order->document_status = SalesOrderStatusEnum::RECEIVED;
            }

            $order->forceFill($this->data->sent([
                'quote_id' => $this->data->quote?->getId(),
                'customer_po_number' => $this->data->customer_po_number,
                'customer_po_date' => $this->data->customer_po_date,
                'signature_checklist' => $this->data->signature_checklist,
                'currency' => $this->data->currency,
                'incoterm' => $this->data->incoterm,
                'consignee' => $this->data->consignee,
                'payment_terms_days' => $this->data->payment_terms_days,
                'requested_date' => $this->data->requested_date,
                'notes' => $this->data->notes,
            ]));
            $order->review = null;
            $order->reviewed_at = null;

            if ($this->data->changes('lines')) {
                $this->persistLines($order, $this->data);
            } else {
                $order->saveOrFail();
            }

            return $order->fresh(['lines']);
        });
    }
}
