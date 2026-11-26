<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\Services;

use Illuminate\Database\Eloquent\Collection;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Purchasing\DataTransferObject\PurchaseOrderMatch;
use Kanvas\Scribe\Purchasing\Enums\PurchaseOrderConfigurationEnum;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrderLine;
use Kanvas\Scribe\Support\PlainNumber;

/**
 * The three-way match: what was ordered, what was received, and what the vendor bills. A bill line is paired with
 * the order line that has its SKU or, without one, its description. A line may not bill more than was received, and
 * its unit price may not differ from the order's, each within the company's tolerance. What the other bills linked to
 * the order already bill counts too, so the same goods are not billed twice.
 */
class PurchaseOrderMatchService
{
    public function match(PurchaseOrder $order, Bill $bill): PurchaseOrderMatch
    {
        $company = $order->company;
        $quantityTolerance = (float) $company->get(
            PurchaseOrderConfigurationEnum::OVER_RECEIPT_TOLERANCE_PERCENT->value
        );
        $priceTolerance = (float) $company->get(PurchaseOrderConfigurationEnum::PRICE_TOLERANCE_PERCENT->value);
        $orderLines = $order->lines()->orderBy('line_number')->get();
        $billedBefore = $this->billedOnOtherBills($order, $bill, $orderLines);
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
                $found[] = $this->discrepancy(
                    "line_{$number}_not_on_order",
                    "Bill line {$number} is not on the order."
                );

                continue;
            }

            $billed[$orderLine->line_number] = ($billed[$orderLine->line_number] ?? 0.0) + (float) $billLine->quantity;

            $priceDiffers = $this->priceDiffers(
                (float) $orderLine->unit_cost,
                (float) $billLine->unit_price_native,
                $priceTolerance
            );

            if ($priceDiffers) {
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

            if ($quantity === null) {
                continue;
            }

            $before = $billedBefore[$orderLine->line_number] ?? 0.0;
            $billable = (float) $orderLine->received_qty * (1 + $quantityTolerance / 100) + 1e-9;

            if ($quantity + $before > $billable) {
                $found[] = $this->discrepancy(
                    "line_{$orderLine->line_number}_billed_more_than_received",
                    "Order line {$orderLine->line_number} is billed for more than was received"
                        . ($before > 0
                            ? ': ' . PlainNumber::format($before) . ' is already on other bills linked to the order.'
                            : '.'),
                    orderValue: PlainNumber::format((float) $orderLine->order_qty),
                    receivedValue: PlainNumber::format((float) $orderLine->received_qty),
                    billedValue: PlainNumber::format($quantity + $before)
                );
            }
        }

        return new PurchaseOrderMatch($found);
    }

    /**
     * @param Collection<int, PurchaseOrderLine> $orderLines
     *
     * @return array<int, float> quantity by order line number
     */
    private function billedOnOtherBills(PurchaseOrder $order, Bill $bill, Collection $orderLines): array
    {
        $others = Bill::query()
            ->fromApp($order->app)
            ->fromCompany($order->company)
            ->notDeleted()
            ->where('purchase_order_id', $order->getId())
            ->where('id', '!=', $bill->getId())
            ->where('document_status', '!=', BillDocumentStatusEnum::VOIDED->value)
            ->with('lines')
            ->get();
        $billed = [];

        foreach ($others->flatMap->lines as $line) {
            $number = $this->orderLineFor($orderLines, $line->sku, $line->description)?->line_number;

            if ($number !== null) {
                $billed[$number] = ($billed[$number] ?? 0.0) + (float) $line->quantity;
            }
        }

        return $billed;
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
