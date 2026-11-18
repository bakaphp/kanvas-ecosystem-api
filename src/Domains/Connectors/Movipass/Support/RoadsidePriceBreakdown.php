<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Support;

/**
 * Rounds at every step, in the order the tariff sheet prints it, so each line on the receipt is
 * the cent amount that was actually added — the total always equals the sum of its lines.
 */
final class RoadsidePriceBreakdown
{
    public function __construct(
        public readonly float $subtotal,
        public readonly float $surchargeRate,
        public readonly float $surchargeAmount,
        public readonly float $taxRate,
        public readonly float $taxAmount,
        public readonly float $total,
    ) {
    }

    public static function compute(float $subtotal, float $surchargeRate, float $taxRate): self
    {
        $subtotal = round($subtotal, 2);
        $surchargeAmount = round($subtotal * $surchargeRate, 2);
        $taxableBase = round($subtotal + $surchargeAmount, 2);
        $taxAmount = round($taxableBase * $taxRate, 2);

        return new self(
            subtotal: $subtotal,
            surchargeRate: $surchargeRate,
            surchargeAmount: $surchargeAmount,
            taxRate: $taxRate,
            taxAmount: $taxAmount,
            total: round($taxableBase + $taxAmount, 2),
        );
    }

    public function taxableBase(): float
    {
        return round($this->subtotal + $this->surchargeAmount, 2);
    }

    public function toArray(): array
    {
        return [
            'subtotal' => $this->subtotal,
            'surcharge_rate' => $this->surchargeRate,
            'surcharge_amount' => $this->surchargeAmount,
            'taxable_base' => $this->taxableBase(),
            'tax_rate' => $this->taxRate,
            'tax_amount' => $this->taxAmount,
            'total' => $this->total,
        ];
    }
}
