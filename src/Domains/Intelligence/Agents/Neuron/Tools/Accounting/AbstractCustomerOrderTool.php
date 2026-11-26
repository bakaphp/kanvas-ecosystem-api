<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Closure;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\FindsTenantRecordForTool;
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
    use FindsTenantRecordForTool;
    use HasKanvasContext;
    use TrackByInputs;

    protected function salesOrderByNumber(string $orderNumber): ?SalesOrder
    {
        $order = $this->findTenantRecordOrNotFound(
            SalesOrder::class,
            'order_number',
            $orderNumber,
            'sales order'
        );

        return $order instanceof SalesOrder ? $order : null;
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

        return [$doneKey => true] + $this->summary($order) + ['differences' => $order->differences()];
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
        $open = array_column($order->openDifferences(), 'key');

        return [
            'order_number' => $order->order_number,
            'document_status' => $order->document_status->value,
            'po_source' => $order->po_source->value,
            'customer_organization_id' => $order->customer_organization_id,
            'customer_po_number' => $order->customer_po_number,
            'currency' => $order->currency,
            'order_total' => (float) $order->order_total,
            'open_differences' => $open,
            'etd' => $order->etd?->toDateString(),
            'eta' => $order->eta?->toDateString(),
            'next' => $this->nextStep($order, $open),
        ];
    }

    /**
     * @param list<string> $open
     */
    private function nextStep(SalesOrder $order, array $open): string
    {
        return match ($order->document_status) {
            SalesOrderStatusEnum::RECEIVED => match (true) {
                $order->reviewed_at === null => 'Review it against its quote with review_customer_po.',
                $open !== [] => 'Clarify each open difference with the customer and record it with '
                    . 'resolve_po_difference.',
                default => 'Ready to confirm: confirm_customer_order once Operations has confirmed it is feasible.',
            },
            SalesOrderStatusEnum::CONFIRMED => 'Waiting for Operations: answer_customer_order accepts it with firm '
                . 'dates or returns it with a reason.',
            SalesOrderStatusEnum::RETURNED => 'Operations returned it: renegotiate with the customer, correct the '
                . 'order and review it again.',
            SalesOrderStatusEnum::ACCEPTED => 'Accepted with firm dates; it can go to purchasing and shipping.',
            default => 'Nothing more to do on this order in this step.',
        };
    }
}
