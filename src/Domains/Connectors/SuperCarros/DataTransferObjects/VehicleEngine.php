<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SuperCarros\DataTransferObjects;

use Spatie\LaravelData\Data;

class VehicleEngine extends Data
{
    public function __construct(
        public string $type,
        public string $cylinders,
        public string $description
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            type: $data['EngineType'] ?? '',
            cylinders: $data['EngineCylinders'] ?? '',
            description: $data['Engine'] ?? ''
        );
    }
}
