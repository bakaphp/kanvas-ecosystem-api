<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SuperCarros\DataTransferObjects;

use Spatie\LaravelData\Data;

class VehicleSpecifications extends Data
{
    public function __construct(
        public int $doors,
        public int $passengers,
        public string $usage,
        public string $usageUnit,
        public string $traction
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            doors: (int) ($data['Doors'] ?? 0),
            passengers: (int) ($data['Passengers'] ?? 0),
            usage: $data['Usage'] ?? '',
            usageUnit: $data['UsageUnit'] ?? '',
            traction: $data['Traction'] ?? ''
        );
    }
}
