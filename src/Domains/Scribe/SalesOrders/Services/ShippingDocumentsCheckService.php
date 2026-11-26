<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Services;

use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use Kanvas\Scribe\SalesOrders\Services\Concerns\BuildsDifferences;

/**
 * Cross-checks the four documents of a shipment: the order (PO or proforma), the commercial invoice, the packing
 * list and the draft bill of lading. The invoice is meant to follow what was really loaded, so a difference with
 * the order is not an error by itself: it has to be explained. Lines are matched by position.
 */
class ShippingDocumentsCheckService
{
    use BuildsDifferences;

    /**
     * @param list<array{quantity: float|int}> $packingList
     * @param array{total_quantity: float|int} $blDraft
     *
     * @return list<array{key: string, field: string, po_value: string|null, quote_value: string|null, resolution: null}>
     */
    public function differences(SalesOrder $order, Invoice $invoice, array $packingList, array $blDraft): array
    {
        $orderQuantities = $order->lines()->orderBy('line_number')->pluck('quantity')->all();
        $invoiceQuantities = $invoice->lines()->orderBy('sort_order')->pluck('quantity')->all();
        $packedQuantities = array_column($packingList, 'quantity');

        $found = [
            $this->ifChanged(
                'invoice_total',
                'Invoice total against the order',
                $this->number((float) $order->order_total),
                $this->number((float) $invoice->total_native)
            ),
        ];

        foreach (range(0, max(count($orderQuantities), count($invoiceQuantities), count($packedQuantities)) - 1) as $index) {
            $number = $index + 1;

            $found[] = $this->ifChanged(
                "invoice_line_{$number}_quantity",
                "Line {$number} quantity, invoice against the order",
                $this->quantity($orderQuantities[$index] ?? null),
                $this->quantity($invoiceQuantities[$index] ?? null)
            );
            $found[] = $this->ifChanged(
                "packing_line_{$number}_quantity",
                "Line {$number} quantity, packing list against the invoice",
                $this->quantity($invoiceQuantities[$index] ?? null),
                $this->quantity($packedQuantities[$index] ?? null)
            );
        }

        $found[] = $this->ifChanged(
            'bl_total_quantity',
            'Total quantity, draft bill of lading against the packing list',
            $this->number((float) array_sum($packedQuantities)),
            $this->number((float) $blDraft['total_quantity'])
        );

        return array_values(array_filter($found));
    }

    private function quantity(float|int|string|null $value): ?string
    {
        return $value === null ? null : $this->number((float) $value);
    }
}
