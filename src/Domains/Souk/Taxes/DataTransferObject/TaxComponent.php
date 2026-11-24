<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\DataTransferObject;

use Spatie\LaravelData\Data;

class TaxComponent extends Data
{
    public function __construct(
        public readonly string $lineId,
        public readonly string $taxType,
        public readonly ?string $jurisdiction,
        public readonly string $rate,
        public readonly string $taxableAmount,
        public readonly string $taxAmount,
        public readonly ?string $taxabilityReason = null,
        public readonly ?string $sourcing = null,
    ) {
    }
}
