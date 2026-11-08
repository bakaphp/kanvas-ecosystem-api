<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * The export block an E46 carries. incoterm is the ICC code (FOB, CIF, ...), destinationCountry the
 * ISO country the goods go to; estimatedForeignValue is the value in the destination currency, when known.
 */
class EcfExport extends Data
{
    public function __construct(
        public readonly string $incoterm,
        public readonly string $destinationCountry,
        public readonly ?float $estimatedForeignValue = null,
    ) {
    }
}
