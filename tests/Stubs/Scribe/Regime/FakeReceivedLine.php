<?php

declare(strict_types=1);

namespace Tests\Stubs\Scribe\Regime;

use Kanvas\Scribe\ElectronicInvoicing\Contracts\ReceivedFiscalLineInterface;

final readonly class FakeReceivedLine implements ReceivedFiscalLineInterface
{
    public function __construct(
        private string $description,
        private float $unitPrice,
        private float $taxRate,
    ) {
    }

    public function lineNumber(): int
    {
        return 1;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function quantity(): float
    {
        return 1.0;
    }

    public function unitPrice(): float
    {
        return $this->unitPrice;
    }

    public function discountAmount(): float
    {
        return 0.0;
    }

    public function taxRate(): float
    {
        return $this->taxRate;
    }

    public function taxAmount(): float
    {
        return round($this->unitPrice * $this->taxRate, 2);
    }

    public function taxMetadata(): array
    {
        return ['zz_vat' => 'standard'];
    }

    public function code(): ?string
    {
        return null;
    }
}
