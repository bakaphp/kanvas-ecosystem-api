<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\Services;

use Baka\Support\Money;
use Baka\Support\Number;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Purchasing\DataTransferObject\PurchaseOrderMatch;
use Kanvas\Scribe\Purchasing\Enums\PurchaseOrderConfigurationEnum;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrderLine;

/**
 * The three-way match: what was ordered, what was received, and what the vendor bills. A bill line is paired with
 * the order lines that have its SKU or, without one, its description. A line may not bill more than was received, and
 * its unit price may not differ from the order's, each within the company's tolerance. What the other bills linked to
 * the order already bill counts too, so the same goods are not billed twice.
 */
class PurchaseOrderMatchService
{
    /** Quantities and unit costs are DECIMAL(…,6). */
    private const int DECIMALS = 6;

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
        $pools = [];

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
            $lines = $this->orderLinesFor($orderLines, $billLine->sku, $billLine->description);

            if ($lines->isEmpty()) {
                $found[] = $this->discrepancy(
                    "line_{$number}_not_on_order",
                    "Bill line {$number} is not on the order."
                );

                continue;
            }

            $pool = $lines->first()->line_number;
            $pools[$pool] = $lines;
            $price = Money::of($billLine->unit_price_native);
            $billed[$pool][] = [
                'price' => $price,
                'quantity' => Money::of($billLine->quantity),
            ];

            if ($this->linesAtPrice($lines, $price, $priceTolerance)->isEmpty()) {
                $found[] = $this->discrepancy(
                    "line_{$number}_price",
                    "Bill line {$number} charges a different unit price than the order.",
                    orderValue: Number::withoutTrailingZeros($lines->first()->unit_cost, self::DECIMALS),
                    billedValue: Number::withoutTrailingZeros($price, self::DECIMALS)
                );
            }
        }

        foreach ($billed as $pool => $units) {
            $lines = $pools[$pool];
            $before = $billedBefore[$pool] ?? [];
            $billedHere = Money::sum(...array_column($units, 'quantity'));
            $onOtherBills = Money::sum(...array_column($before, 'quantity'));
            $received = $this->total($lines, 'received_qty');

            if ($billedHere->plus($onOtherBills)->isGreaterThan($this->billable($received, $quantityTolerance))) {
                $found[] = $this->discrepancy(
                    "line_{$pool}_billed_more_than_received",
                    "Order line {$pool} is billed for more than was received"
                        . ($onOtherBills->isPositive()
                            ? ': ' . Number::withoutTrailingZeros($onOtherBills, self::DECIMALS)
                                . ' is already on other bills linked to the order.'
                            : '.'),
                    orderValue: Number::withoutTrailingZeros($this->total($lines, 'order_qty'), self::DECIMALS),
                    receivedValue: Number::withoutTrailingZeros($received, self::DECIMALS),
                    billedValue: Number::withoutTrailingZeros($billedHere->plus($onOtherBills), self::DECIMALS)
                );

                continue;
            }

            $pastReceipts = $this->billedPastReceiptsAtPrice(
                $lines,
                [...$before, ...$units],
                $priceTolerance,
                $quantityTolerance
            );

            foreach ($pastReceipts as $lineNumber => $past) {
                $found[] = $this->discrepancy(
                    "line_{$lineNumber}_billed_more_than_received_at_price",
                    "Order line {$lineNumber}: " . Number::withoutTrailingZeros($past['short'], self::DECIMALS)
                        . ' more units are billed at its price than arrived at that price.',
                    orderValue: Number::withoutTrailingZeros($past['unit_cost'], self::DECIMALS),
                    receivedValue: Number::withoutTrailingZeros($past['received'], self::DECIMALS),
                    billedValue: Number::withoutTrailingZeros($past['billed'], self::DECIMALS)
                );
            }
        }

        return new PurchaseOrderMatch($found);
    }

    /**
     * What the order's other bills already bill, by product: each line's quantity and the price it charges.
     *
     * @param Collection<int, PurchaseOrderLine> $orderLines
     *
     * @return array<int, list<array{price: BigDecimal, quantity: BigDecimal}>>
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
            $lines = $this->orderLinesFor($orderLines, $line->sku, $line->description);

            if ($lines->isNotEmpty()) {
                $billed[$lines->first()->line_number][] = [
                    'price' => Money::of($line->unit_price_native),
                    'quantity' => Money::of($line->quantity),
                ];
            }
        }

        return $billed;
    }

    /**
     * One product ordered at several prices: a unit is billed at a price only against a unit that arrived at that
     * price, and a received unit is counted once, whatever bill line or other bill draws on it. Billed units are
     * placed cheapest first, each on the cheapest receipts its price agrees with, which leaves the dearer receipts
     * for the dearer units: when that placement leaves units over, no other one fits them.
     *
     * @param Collection<int, PurchaseOrderLine>                  $lines
     * @param list<array{price: BigDecimal, quantity: BigDecimal}> $units
     *
     * @return array<int, array{unit_cost: string, received: BigDecimal, billed: BigDecimal, short: BigDecimal}> by
     *                                                                                                           the first order line at the price left short
     */
    private function billedPastReceiptsAtPrice(
        Collection $lines,
        array $units,
        float $priceTolerance,
        float $quantityTolerance
    ): array {
        $left = [];
        $short = [];

        foreach ($lines as $line) {
            $left[$line->line_number] = $this->billable(Money::of($line->received_qty ?? 0), $quantityTolerance);
        }

        usort($units, static fn (array $a, array $b): int => $a['price']->compareTo($b['price']));

        foreach ($units as $unit) {
            $atPrice = $this->linesAtPrice($lines, $unit['price'], $priceTolerance);
            $quantity = $unit['quantity'];
            $cheapestFirst = $atPrice->sort(
                static fn (PurchaseOrderLine $a, PurchaseOrderLine $b): int => Money::of($a->unit_cost)
                    ->compareTo(Money::of($b->unit_cost))
            );

            foreach ($cheapestFirst as $line) {
                $taken = BigDecimal::min($quantity, $left[$line->line_number]);
                $left[$line->line_number] = $left[$line->line_number]->minus($taken);
                $quantity = $quantity->minus($taken);
            }

            if ($atPrice->isNotEmpty() && $quantity->isPositive()) {
                $first = $atPrice->first()->line_number;
                $short[$first] = [
                    'lines' => $atPrice,
                    'short' => $quantity->plus($short[$first]['short'] ?? 0),
                ];
            }
        }

        return array_map(
            function (array $past) use ($left, $quantityTolerance): array {
                $received = $this->total($past['lines'], 'received_qty');
                $unused = Money::sum(...$past['lines']->map(
                    static fn (PurchaseOrderLine $line): BigDecimal => $left[$line->line_number]
                ));

                return [
                    'unit_cost' => $past['lines']->first()->unit_cost,
                    'received' => $received,
                    'billed' => $this->billable($received, $quantityTolerance)->minus($unused)->plus($past['short']),
                    'short' => $past['short'],
                ];
            },
            $short
        );
    }

    private function billable(BigDecimal $received, float $tolerancePercent): BigDecimal
    {
        return $received
            ->multipliedBy(Money::of(100)->plus(Money::of($tolerancePercent)))
            ->dividedBy(100, 10, RoundingMode::HalfUp);
    }

    /**
     * @param Collection<int, PurchaseOrderLine> $lines
     *
     * @return Collection<int, PurchaseOrderLine>
     */
    private function linesAtPrice(Collection $lines, BigDecimal $price, float $priceTolerance): Collection
    {
        return $lines
            ->reject(fn (PurchaseOrderLine $line): bool => $this->priceDiffers(
                Money::of($line->unit_cost),
                $price,
                $priceTolerance
            ))
            ->values();
    }

    /**
     * @param Collection<int, PurchaseOrderLine> $lines
     */
    private function total(Collection $lines, string $column): BigDecimal
    {
        return Money::sum(...$lines->map(
            static fn (PurchaseOrderLine $line): string => (string) ($line->{$column} ?? 0)
        ));
    }

    /**
     * Every order line of the product a bill line names. An order can carry one product on several lines (two
     * deliveries, two prices), and a bill does not say which one it means, so they are matched as one quantity.
     * The bill line finds one order line, by SKU or else by description, and that line's own product decides the
     * group: a bill naming the product by its code and again by a description draws on the same receipts.
     *
     * @param Collection<int, PurchaseOrderLine> $orderLines
     *
     * @return Collection<int, PurchaseOrderLine>
     */
    private function orderLinesFor(Collection $orderLines, ?string $sku, string $description): Collection
    {
        $sku = $this->normalized($sku);
        $description = $this->normalized($description);
        $named = $orderLines->first(
            fn (PurchaseOrderLine $line): bool => $sku !== ''
                ? $this->normalized($line->sku) === $sku
                : $this->normalized($line->description) === $description
        );

        if ($named === null) {
            return new Collection();
        }

        $product = $this->productOf($named);

        return $orderLines
            ->filter(fn (PurchaseOrderLine $line): bool => $this->productOf($line) === $product)
            ->values();
    }

    private function productOf(PurchaseOrderLine $line): string
    {
        $sku = $this->normalized($line->sku);

        return $sku !== '' ? "sku:{$sku}" : 'description:' . $this->normalized($line->description);
    }

    private function normalized(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    private function priceDiffers(BigDecimal $ordered, BigDecimal $billed, float $tolerancePercent): bool
    {
        return $billed->minus($ordered)->abs()->multipliedBy(100)
            ->isGreaterThan($ordered->multipliedBy(Money::of($tolerancePercent)));
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
