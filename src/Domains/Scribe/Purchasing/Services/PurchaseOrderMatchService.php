<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\Services;

use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Purchasing\Enums\PurchaseOrderConfigurationEnum;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrderLine;
use Kanvas\Scribe\Purchasing\Support\PlainNumber;

/**
 * The three-way match: what was ordered, what was received, and what the vendor bills. A bill line is paired with
 * the order line that has its SKU or, without one, its description. A line may not bill more than was received, and
 * its unit price may not differ from the order's, each within the company's tolerance. Bills already linked to the
 * order are not counted against what was received.
 */
class PurchaseOrderMatchService
{
    public function match(PurchaseOrder $order, Bill $bill): PurchaseOrderMatch
    {
        $company = $order->company;
        $quantityTolerance = (float) $company->get(PurchaseOrderConfigurationEnum::OVER_RECEIPT_TOLERANCE_PERCENT->value);
        $priceTolerance = (float) $company->get(PurchaseOrderConfigurationEnum::PRICE_TOLERANCE_PERCENT->value);
        $orderLines = $order->lines()->orderBy('line_number')->get();
        $found = [];
        $billed = [];

        if ((int) $bill->vendor_organization_id !== (int) $order->vendor_organization_id) {
            $found[] = $this->discrepancy('vendor', 'The bill is from another vendor than the order.');
        }

        if (strtoupper($bill->currency) !== strtoupper((string) $order->currency)) {
            $found[] = $this->discrepancy(
                'currency',
                'The bill and the order are in different currencies.',
                orderValue: $order->currency,
                billedValue: $bill->currency
            );
        }

        foreach ($bill->lines()->orderBy('sort_order')->get() as $index => $billLine) {
            $number = $index + 1;
            $orderLine = $this->orderLineFor($orderLines, $billLine->sku, $billLine->description);

            if ($orderLine === null) {
                $found[] = $this->discrepancy("line_{$number}_not_on_order", "Bill line {$number} is not on the order.");

                continue;
            }

            $billed[$orderLine->line_number] = ($billed[$orderLine->line_number] ?? 0.0) + (float) $billLine->quantity;

            if ($this->priceDiffers((float) $orderLine->unit_cost, (float) $billLine->unit_price_native, $priceTolerance)) {
                $found[] = $this->discrepancy(
                    "line_{$number}_price",
                    "Bill line {$number} charges a different unit price than the order.",
                    orderValue: PlainNumber::format((float) $orderLine->unit_cost),
                    billedValue: PlainNumber::format((float) $billLine->unit_price_native)
                );
            }
        }

        foreach ($orderLines as $orderLine) {
            $quantity = $billed[$orderLine->line_number] ?? null;

            if ($quantity !== null && $quantity > (float) $orderLine->received_qty * (1 + $quantityTolerance / 100) + 1e-9) {
                $found[] = $this->discrepancy(
                    "line_{$orderLine->line_number}_billed_more_than_received",
                    "Order line {$orderLine->line_number} is billed for more than was received.",
                    orderValue: PlainNumber::format((float) $orderLine->order_qty),
                    receivedValue: PlainNumber::format((float) $orderLine->received_qty),
                    billedValue: PlainNumber::format($quantity)
                );
            }
        }

        return new PurchaseOrderMatch($found);
    }

    /**
     * @param iterable<int, PurchaseOrderLine> $orderLines
     */
    private function orderLineFor(iterable $orderLines, ?string $sku, string $description): ?PurchaseOrderLine
    {
        $sku = $sku === null ? '' : mb_strtolower(trim($sku));
        $description = mb_strtolower(trim($description));

        foreach ($orderLines as $line) {
            $bySku = $sku !== '' && mb_strtolower(trim((string) $line->sku)) === $sku;
            $byDescription = $sku === '' && mb_strtolower(trim((string) $line->description)) === $description;

            if ($bySku || $byDescription) {
                return $line;
            }
        }

        return null;
    }

    private function priceDiffers(float $ordered, float $billed, float $tolerancePercent): bool
    {
        return abs($billed - $ordered) > $ordered * $tolerancePercent / 100 + 1e-9;
    }

    /**
     * @return array{key: string, message: string, order_value: string|null, received_value: string|null, billed_value: string|null}
     */
    private function discrepancy(
        string $key,
        string $message,
        ?string $orderValue = null,
        ?string $receivedValue = null,
        ?string $billedValue = null,
    ): array {
        return [
            'key' => $key,
            'message' => $message,
            'order_value' => $orderValue,
            'received_value' => $receivedValue,
            'billed_value' => $billedValue,
        ];
    }
}
