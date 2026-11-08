<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DataTransferObject;

use Spatie\LaravelData\Data;

class EcfTotals extends Data
{
    public function __construct(
        public readonly float $exempt,
        public readonly float $taxed,
        public readonly float $itbis,
        public readonly float $total,
        public readonly float $itbisWithheld = 0.0,
        public readonly float $isrWithheld = 0.0,
    ) {
    }
}
