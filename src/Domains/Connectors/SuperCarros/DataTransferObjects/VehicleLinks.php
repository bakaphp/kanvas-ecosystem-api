<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SuperCarros\DataTransferObjects;

use Spatie\LaravelData\Data;

class VehicleLinks extends Data
{
    public function __construct(
        public string $ad,
        public string $financing
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            ad: $data['SuperCarrosAdLink'] ?? '',
            financing: $data['SuperCarrosFinanzLink'] ?? ''
        );
    }
}
