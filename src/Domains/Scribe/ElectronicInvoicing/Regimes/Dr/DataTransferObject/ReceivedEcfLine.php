<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject;

use Kanvas\Scribe\ElectronicInvoicing\Contracts\ReceivedFiscalLineInterface;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfItbisRateEnum;
use Spatie\LaravelData\Data;

/**
 * One item of a received e-CF. DGII's XML carries no per-line tax amount, so `tax` is derived from the
 * line amount and its ITBIS rate and the rounding remainder is moved onto one line, so the lines always
 * add up to the document's TotalITBIS.
 */
class ReceivedEcfLine extends Data implements ReceivedFiscalLineInterface
{
    public function __construct(
        public readonly int $lineNumber,
        public readonly string $description,
        public readonly float $quantity,
        public readonly float $unitPrice,
        public readonly float $discountAmount,
        public readonly float $amount,
        public readonly EcfItbisRateEnum $itbis,
        public readonly float $tax,
        public readonly bool $isService,
        public readonly ?string $code = null,
    ) {
    }

    public function lineNumber(): int
    {
        return $this->lineNumber;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function quantity(): float
    {
        return $this->quantity;
    }

    public function unitPrice(): float
    {
        return $this->unitPrice;
    }

    public function discountAmount(): float
    {
        return $this->discountAmount;
    }

    public function taxRate(): float
    {
        return $this->itbis->percent() / 100;
    }

    public function taxAmount(): float
    {
        return $this->tax;
    }

    public function taxMetadata(): array
    {
        return ['do_itbis' => $this->itbis->value];
    }

    public function code(): ?string
    {
        return $this->code;
    }
}
