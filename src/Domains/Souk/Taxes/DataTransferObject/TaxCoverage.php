<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\DataTransferObject;

use Spatie\LaravelData\Data;

class TaxCoverage extends Data
{
    public function __construct(
        public readonly string $country,
        public readonly ?string $state,
        public readonly string $provider,
        public readonly bool $isSupported,
        public readonly ?string $unsupportedReason = null,
    ) {
    }
}
