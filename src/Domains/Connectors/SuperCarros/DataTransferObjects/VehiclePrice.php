<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SuperCarros\DataTransferObjects;

use Spatie\LaravelData\Data;

class VehiclePrice extends Data
{
    public function __construct(
        public float $amount,
        public string $currency,
        public ?float $dopAmount = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            amount: $data['Price'],
            currency: $data['Currency'],
            dopAmount: $data['PriceDOP'] ?? null
        );
    }
}