<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ChromeData\DataTransferObject;

use Spatie\LaravelData\Data;

class PriceData extends Data
{
    public function __construct(
        public readonly ?float $msrp,
        public readonly ?float $invoice,
        public readonly ?float $destination,
    ) {
    }
}
