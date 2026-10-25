<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\DataTransferObject;

use Spatie\LaravelData\Data;

class TaxReversal extends Data
{
    public function __construct(
        public readonly string $snapshotUuid,
        public readonly string $amount,
        public readonly string $taxAmount,
        public readonly ?string $refundUuid = null,
    ) {
    }
}
