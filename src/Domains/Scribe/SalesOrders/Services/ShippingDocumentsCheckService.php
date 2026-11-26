<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Services;

use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use Kanvas\Scribe\SalesOrders\Services\Concerns\BuildsDifferences;

/**
 * Cross-checks the four documents of a shipment: the order (PO or proforma), the commercial invoice, the packing
 * list and the draft bill of lading. The invoice is meant to follow what was really loaded, so a difference with
 * the order is not an error by itself: it has to be explained. Lines are matched by position, and each pair has to
 * name the same product.
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
    public function differences(
        SalesOrder $order,
        Invoice $invoice,
        array $packingList,
        array $blDraft
    ): array {
        $orderLines = $order->lines()->orderBy('line_number')->get()->values();
        $invoiceLines = $invoice->lines()->orderBy('sort_order')->get()->values();
        $packedQuantities = array_column($packingList, 'quantity');

        $found = [
            $this->ifChanged(
                'invoice_currency',
                'Currency, invoice against the order',
                strtoupper((string) $order->currency),
                strtoupper((string) $invoice->currency)
            ),
            $this->ifChanged(
                'invoice_total',
                'Invoice total against the order',
                $this->number((float) $order->order_total),
                $this->number((float) $invoice->total_native)
            ),
        ];

        $count = max($orderLines->count(), $invoiceLines->count(), count($packedQuantities));

        for ($index = 0; $index < $count; $index++) {
            $number = $index + 1;
            $orderLine = $orderLines[$index] ?? null;
            $invoiceLine = $invoiceLines[$index] ?? null;

            $found[] = $this->productDifference(
                "invoice_line_{$number}_product",
                "Line {$number} product, invoice against the order",
                $orderLine,
                $invoiceLine
            );
            $found[] = $this->ifChanged(
                "invoice_line_{$number}_quantity",
                "Line {$number} quantity, invoice against the order",
                $this->quantity($orderLine?->quantity),
                $this->quantity($invoiceLine?->quantity)
            );
            $found[] = $this->ifChanged(
                "packing_line_{$number}_quantity",
                "Line {$number} quantity, packing list against the invoice",
                $this->quantity($invoiceLine?->quantity),
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

    /**
     * What the check saw of the invoice. The bill of lading is only recorded while the invoice still matches it,
     * so an invoice edited after the check has to be checked again.
     */
    public function invoiceFingerprint(Invoice $invoice): string
    {
        return hash('sha256', (string) json_encode([
            'customer' => (int) $invoice->customer_organization_id,
            'type' => $invoice->document_type->value,
            'currency' => strtoupper((string) $invoice->currency),
            'total' => $this->number((float) $invoice->total_native),
            'lines' => $invoice->lines()
                ->orderBy('sort_order')
                ->get()
                ->map(fn (object $line): array => [
                    'sku' => $line->sku,
                    'description' => $line->description,
                    'quantity' => $this->quantity($line->quantity),
                    'unit_price' => $this->number((float) $line->unit_price_native),
                ])
                ->all(),
        ]));
    }

    private function quantity(float|int|string|null $value): ?string
    {
        return $value === null ? null : $this->number((float) $value);
    }
}
