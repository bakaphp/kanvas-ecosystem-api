<?php

declare(strict_types=1);

namespace Kanvas\Scribe\BillingSchedules\DataTransferObject;

use Baka\Support\Money;
use Baka\Support\Str;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\BillingSchedules\Models\BillingScheduleLine as BillingScheduleLineModel;
use Spatie\LaravelData\Data;

/**
 * Every line is billed again each period, so a bad one is refused with the line it came from instead of
 * being dropped or defaulted into an invoice nobody asked for. `tax_rate` is a percent; when it is given
 * without a tax_amount, the amount is derived from it.
 */
class BillingScheduleLine extends Data
{
    public function __construct(
        public readonly string $description,
        public readonly float $quantity,
        public readonly float $unit_price,
        public readonly float $tax_amount = 0.0,
        public readonly ?float $tax_rate = null,
        public readonly ?int $item_id = null,
        public readonly float $discount_amount = 0.0,
    ) {
    }

    /**
     * @throws ValidationException
     */
    public static function fromInput(mixed $line, int $position): self
    {
        $label = "Line {$position}";

        if (! is_array($line)) {
            throw new ValidationException("{$label}: each line must be an object with a description and a unit_price.");
        }

        $description = Str::trimToNull(is_scalar($line['description'] ?? null) ? (string) $line['description'] : null);
        if ($description === null) {
            throw new ValidationException("{$label}: description is required.");
        }
        if (mb_strlen($description) > 500) {
            throw new ValidationException("{$label}: description is longer than 500 characters.");
        }

        if (! is_numeric($line['unit_price'] ?? null)) {
            throw new ValidationException("{$label}: unit_price is required and must be a number.");
        }

        $quantity = self::optionalNumber($line, 'quantity', $label) ?? 1.0;
        if ($quantity <= 0) {
            throw new ValidationException("{$label}: quantity must be greater than zero.");
        }

        $unitPrice = (float) $line['unit_price'];
        $taxRate = self::optionalNumber($line, 'tax_rate', $label);
        if ($taxRate !== null && ($taxRate < 0 || $taxRate > 100)) {
            throw new ValidationException("{$label}: tax_rate is a percent between 0 and 100.");
        }

        $taxAmount = self::optionalNumber($line, 'tax_amount', $label);
        if ($taxAmount !== null && $taxAmount < 0) {
            throw new ValidationException("{$label}: tax_amount cannot be negative.");
        }

        $discountAmount = self::optionalNumber($line, 'discount_amount', $label) ?? 0.0;
        if ($discountAmount < 0) {
            throw new ValidationException("{$label}: discount_amount cannot be negative.");
        }

        $itemId = $line['item_id'] ?? null;
        if ($itemId !== null && (! is_numeric($itemId) || (int) $itemId != $itemId || (int) $itemId <= 0)) {
            throw new ValidationException("{$label}: item_id must be a positive whole number.");
        }

        return new self(
            description: $description,
            quantity: Money::stored($quantity),
            unit_price: Money::stored($unitPrice),
            tax_amount: Money::stored($taxAmount ?? self::taxFromRate($quantity, $unitPrice, $taxRate)),
            tax_rate: $taxRate,
            item_id: $itemId !== null ? (int) $itemId : null,
            discount_amount: Money::stored($discountAmount),
        );
    }

    /**
     * @param array<array-key, mixed> $lines
     *
     * @return list<self>
     */
    public static function listFromInput(array $lines): array
    {
        $parsed = [];
        foreach (array_values($lines) as $index => $line) {
            $parsed[] = $line instanceof self ? $line : self::fromInput($line, $index + 1);
        }

        return $parsed;
    }

    public static function fromModel(BillingScheduleLineModel $line): self
    {
        return new self(
            description: $line->description,
            quantity: (float) $line->quantity,
            unit_price: (float) $line->unit_price,
            tax_amount: (float) $line->tax_amount,
            tax_rate: $line->tax_rate !== null ? (float) $line->tax_rate : null,
            item_id: $line->item_id,
            discount_amount: (float) $line->discount_amount,
        );
    }

    private static function taxFromRate(float $quantity, float $unitPrice, ?float $taxRate): BigDecimal
    {
        if ($taxRate === null) {
            return BigDecimal::zero();
        }

        return Money::of($quantity)
            ->multipliedBy(Money::of($unitPrice))
            ->multipliedBy(Money::of($taxRate))
            ->dividedBy(100, 4, RoundingMode::HalfUp);
    }

    /**
     * @param array<array-key, mixed> $line
     */
    private static function optionalNumber(array $line, string $key, string $label): ?float
    {
        $value = $line[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw new ValidationException("{$label}: {$key} must be a number.");
        }

        return (float) $value;
    }
}
