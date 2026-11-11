<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject;

use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfItbisRateEnum;
use Spatie\LaravelData\Data;

/**
 * Amounts are in the document currency, ITBIS excluded from unitPrice, already rounded the way Scribe
 * booked them — providers send subtotal/tax as given so they never recompute a cent away from the JE.
 */
class EcfLine extends Data
{
    public function __construct(
        public readonly int $lineNumber,
        public readonly string $code,
        public readonly string $description,
        public readonly float $quantity,
        public readonly float $unitPrice,
        public readonly EcfItbisRateEnum $itbis,
        public readonly bool $isService,
        public readonly float $discountAmount,
        public readonly float $subtotal,
        public readonly float $tax,
        public readonly float $itbisWithheld = 0.0,
        public readonly float $isrWithheld = 0.0,
    ) {
    }
}
