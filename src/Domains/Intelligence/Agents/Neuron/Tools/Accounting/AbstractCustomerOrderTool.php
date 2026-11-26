<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Closure;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\SalesOrders\Enums\SalesOrderStatusEnum;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\TrackByInputs;
use Throwable;

/**
 * Shared shape of the tools that act on a sales order by its number: find it, run the action, and answer with the
 * order's summary and what has to happen next.
 */
abstract class AbstractCustomerOrderTool extends Tool
{
    use HasKanvasContext;
    use TrackByInputs;

    protected function salesOrderByNumber(string $orderNumber): ?SalesOrder
    {
        return SalesOrder::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->where('order_number', trim($orderNumber))
            ->first();
    }

    /**
     * @param Closure(SalesOrder): SalesOrder $act
     *
     * @return array<string, mixed>
     */
    protected function actOn(string $orderNumber, string $doneKey, Closure $act): array
    {
        $order = $this->salesOrderByNumber($orderNumber);

        if ($order === null) {
            return $this->notFound($orderNumber, $doneKey);
        }

        try {
            $order = $act($order);
        } catch (Throwable $e) {
            return [
                $doneKey => false,
                'order_number' => $order->order_number,
                'reason' => 'not_done',
                'message' => $e->getMessage(),
            ];
        }

        return [$doneKey => true] + $this->summary($order) + [
            'differences' => $order->differences(),
            'documents_differences' => $order->documentsDifferences(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function notFound(string $orderNumber, string $doneKey = 'found'): array
    {
        return [
            $doneKey => false,
            'reason' => 'sales_order_not_found',
            'message' => "No sales order numbered {$orderNumber} for this company.",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(SalesOrder $order): array
    {
        $open = $order->openDifferenceKeys();

        return [
            'order_number' => $order->order_number,
            'document_status' => $order->document_status->value,
            'po_source' => $order->po_source->value,
            'customer_organization_id' => $order->customer_organization_id,
            'customer_po_number' => $order->customer_po_number,
            'currency' => $order->currency,
            'order_total' => (float) $order->order_total,
            'open_differences' => $open,
            'open_documents_differences' => $order->openDocumentsDifferenceKeys(),
            'etd' => $order->etd?->toDateString(),
            'eta' => $order->eta?->toDateString(),
            'shipment' => $this->shipment($order),
            'next' => $this->nextStep($order, $open),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function shipment(SalesOrder $order): ?array
    {
        $shipment = $order->shipment;

        return $shipment === null ? null : [
            'bl_number' => $shipment->bl_number,
            'bl_date' => $shipment->bl_date->toDateString(),
            'invoice_due_date' => $shipment->due_date->toDateString(),
            'documents_package_due_on' => $shipment->documents_package_due_on?->toDateString(),
            'documents_package_sent' => $shipment->documents_package_sent_at !== null,
            'delivered_on' => $shipment->delivered_on?->toDateString(),
        ];
    }

    /**
     * @param list<string> $open
     */
    private function nextStep(SalesOrder $order, array $open): string
    {
        return match (true) {
            $order->document_status === SalesOrderStatusEnum::RECEIVED && $order->reviewed_at === null => 'Review it against its quote with review_customer_po.',
            $order->document_status === SalesOrderStatusEnum::RECEIVED && $open !== [] => 'Clarify each open difference with the customer and record it with resolve_po_difference.',
            $order->document_status === SalesOrderStatusEnum::RECEIVED => 'Ready to confirm: confirm_customer_order once Operations has confirmed it is feasible.',
            $order->document_status === SalesOrderStatusEnum::CONFIRMED => 'Waiting for Operations: answer_customer_order accepts it with firm dates or returns it with a reason.',
            $order->document_status === SalesOrderStatusEnum::RETURNED => 'Operations returned it: renegotiate with the customer, correct the order and review it again.',
            $order->document_status === SalesOrderStatusEnum::ACCEPTED && $order->documents_checked_at === null => 'Accepted with firm dates: cross-check the shipping documents with check_shipping_documents before the bill of lading.',
            $order->document_status === SalesOrderStatusEnum::ACCEPTED => 'Documents checked: once every difference is clarified, record the bill of lading with record_shipment.',
            $order->document_status === SalesOrderStatusEnum::SHIPPED => 'Shipped: send the documents package (mark_documents_package_sent), follow get_collection_plan, and confirm_delivery with the proof of delivery.',
            $order->document_status === SalesOrderStatusEnum::DELIVERED => 'Delivered: close_customer_order once its invoice is paid.',
            default => 'Nothing more to do on this order in this step.',
        };
    }
}
