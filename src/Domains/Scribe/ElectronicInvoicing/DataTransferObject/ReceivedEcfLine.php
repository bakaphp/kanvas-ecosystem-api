<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\DataTransferObject;

use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfItbisRateEnum;
use Spatie\LaravelData\Data;

/**
 * One item of a received e-CF. DGII's XML carries no per-line tax amount, so `tax` is derived from the
 * line amount and its ITBIS rate and the rounding remainder is moved onto one line, so the lines always
 * add up to the document's TotalITBIS.
 */
class ReceivedEcfLine extends Data
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
}
