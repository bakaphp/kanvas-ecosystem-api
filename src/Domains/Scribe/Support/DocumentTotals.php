<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Support;

use Baka\Support\Money;

/**
 * Header totals for every Scribe document, native and base. Each part is rounded to the stored scale first and
 * the total is built from the rounded parts, never converted on its own: the issue JE debits `total_base` and
 * credits `subtotal_base - discount_base + tax_base`, so a total converted separately left a foreign-currency
 * document unbalanced by a rounding step.
 */
final class DocumentTotals
{
    /**
     * @param iterable<array{subtotal: float, tax: float, discount?: float}> $lines
     * @return array{0: array{subtotal: float, tax: float, discount: float, total: float}, 1: array{subtotal: float, tax: float, discount: float, total: float}}
     */
    public static function compute(iterable $lines, float|string $fxRate): array
    {
        $subtotals = [];
        $taxes = [];
        $discounts = [];

        foreach ($lines as $line) {
            $subtotals[] = $line['subtotal'];
            $taxes[] = $line['tax'];
            $discounts[] = $line['discount'] ?? 0.0;
        }

        $native = self::assemble(
            Money::stored(Money::sum(...$subtotals)),
            Money::stored(Money::sum(...$taxes)),
            Money::stored(Money::sum(...$discounts)),
        );

        $base = self::assemble(
            Money::toBase($native['subtotal'], $fxRate),
            Money::toBase($native['tax'], $fxRate),
            Money::toBase($native['discount'], $fxRate),
        );

        return [$native, $base];
    }

    /**
     * @return array{subtotal: float, tax: float, discount: float, total: float}
     */
    private static function assemble(float $subtotal, float $tax, float $discount): array
    {
        return [
            'subtotal' => $subtotal,
            'tax' => $tax,
            'discount' => $discount,
            'total' => Money::stored(Money::of($subtotal)->minus(Money::of($discount))->plus(Money::of($tax))),
        ];
    }
}
