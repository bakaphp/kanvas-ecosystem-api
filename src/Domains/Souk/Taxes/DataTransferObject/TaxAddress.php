<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\DataTransferObject;

use Spatie\LaravelData\Data;

class TaxAddress extends Data
{
    public function __construct(
        public readonly ?string $country,
        public readonly ?string $state,
        public readonly ?string $city,
        public readonly ?string $postalCode,
        public readonly ?string $line1 = null,
    ) {
    }

    public function isSufficientFor(string $country): bool
    {
        return match (strtoupper($country)) {
            'DO' => $this->country !== null,
            'US' => $this->country !== null && $this->state !== null && $this->postalCode !== null,
            'MX' => $this->country !== null && $this->postalCode !== null,
            default => $this->country !== null,
        };
    }
}
