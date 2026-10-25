<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\DataTransferObject;

use Kanvas\Souk\Support\Money;
use Spatie\LaravelData\Data;

class TaxableLine extends Data
{
    public function __construct(
        public readonly string $lineId,
        public readonly string $reference,
        public readonly string $unitPrice,
        public readonly int $quantity,
        public readonly bool $isTaxable,
        public readonly ?string $taxCategory = null,
        public readonly ?string $taxCodeOverride = null,
        public readonly bool $isShipping = false,
    ) {
    }

    public function subtotal(): string
    {
        return (string) Money::roundCollect(
            Money::of($this->unitPrice)->multipliedBy($this->quantity)
        );
    }
}
