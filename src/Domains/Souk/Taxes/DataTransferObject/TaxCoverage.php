<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\DataTransferObject;

use Kanvas\Souk\Taxes\Enums\TaxProviderEnum;
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

    public static function unsupported(string $country, ?string $state, string $reason): self
    {
        return new self(
            country: $country,
            state: $state,
            provider: TaxProviderEnum::NONE->value,
            isSupported: false,
            unsupportedReason: $reason,
        );
    }
}
