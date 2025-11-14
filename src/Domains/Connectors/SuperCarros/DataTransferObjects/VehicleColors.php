<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SuperCarros\DataTransferObjects;

use Spatie\LaravelData\Data;

class VehicleColors extends Data
{
    public function __construct(
        public string $exterior,
        public string $interior
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            exterior: $data['ColorExterior'],
            interior: $data['ColorInterior']
        );
    }
}