<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Services;

use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use Kanvas\Scribe\SalesOrders\Services\Concerns\BuildsDifferences;

/**
 * Compares a sales order with its quote, and with the agreed terms when they are given, and lists every
 * difference. Lines are matched by position.
 */
class SalesOrderReviewService
{
    use BuildsDifferences;

    /**
     * @param array{incoterm?: string|null, payment_terms_days?: int|null} $agreement
     *
     * @return list<array{key: string, field: string, po_value: string|null, quote_value: string|null, resolution: null}>
     */
    public function differences(SalesOrder $order, Quote $quote, array $agreement = []): array
    {
        $found = [
            $this->ifChanged(
                'currency',
                'currency',
                $order->currency,
                $quote->currency
            ),
            $this->ifChanged(
                'total',
                'total',
                $this->number((float) $order->order_total),
                $this->number((float) $quote->total_native)
            ),
            ...$this->lineDifferences($order, $quote),
            $this->ifAgreedDiffers('incoterm', $order->incoterm, $agreement['incoterm'] ?? null),
            $this->ifAgreedDiffers(
                'payment_terms_days',
                $order->payment_terms_days === null ? null : (string) $order->payment_terms_days,
                isset($agreement['payment_terms_days']) ? (string) $agreement['payment_terms_days'] : null
            ),
        ];

        return array_values(array_filter($found));
    }

    /**
     * @return list<array{key: string, field: string, po_value: string|null, quote_value: string|null, resolution: null}|null>
     */
    private function lineDifferences(SalesOrder $order, Quote $quote): array
    {
        $orderLines = $order->lines()->orderBy('line_number')->get()->values();
        $quoteLines = $quote->lines()->orderBy('sort_order')->get()->values();
        $found = [];

        foreach (range(0, max($orderLines->count(), $quoteLines->count()) - 1) as $index) {
            $number = $index + 1;
            $orderLine = $orderLines[$index] ?? null;
            $quoteLine = $quoteLines[$index] ?? null;

            if ($quoteLine === null) {
                $found[] = $this->difference(
                    "line_{$number}_missing_in_quote",
                    "Line {$number}",
                    $orderLine->description,
                    null
                );

                continue;
            }

            if ($orderLine === null) {
                $found[] = $this->difference(
                    "line_{$number}_missing_in_po",
                    "Line {$number}",
                    null,
                    $quoteLine->description
                );

                continue;
            }

            $found[] = $this->ifChanged(
                "line_{$number}_quantity",
                "Line {$number} quantity",
                $this->number((float) $orderLine->quantity),
                $this->number((float) $quoteLine->quantity)
            );
            $found[] = $this->ifChanged(
                "line_{$number}_unit_price",
                "Line {$number} unit price",
                $this->number((float) $orderLine->unit_price),
                $this->number((float) $quoteLine->unit_price_native)
            );
        }

        return $found;
    }

    /**
     * @return array{key: string, field: string, po_value: string|null, quote_value: string|null, resolution: null}|null
     */
    private function ifAgreedDiffers(string $key, ?string $orderValue, ?string $agreedValue): ?array
    {
        if ($agreedValue === null || strcasecmp((string) $orderValue, $agreedValue) === 0) {
            return null;
        }

        return $this->difference($key, $key, $orderValue, $agreedValue);
    }
}
