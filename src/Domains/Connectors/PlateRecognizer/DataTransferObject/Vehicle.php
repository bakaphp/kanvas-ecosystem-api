<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PlateRecognizer\DataTransferObject;

use Spatie\LaravelData\Data;

class Vehicle extends Data
{
    public function __construct(
        public readonly string $plateNumber,
        public readonly float $confidence,
        public readonly string $region,
        public readonly string $make,
        public readonly string $model,
        public readonly string $color,
        public readonly string $orientation,
        public readonly string $type,
        public readonly float $vehicleScore,
        public readonly ?array $vehicleBox = null,
        public readonly ?array $plateBox = null,
        public readonly ?string $direction = null,
        public readonly float $directionScore = 0,
        public readonly array $rawData = []
    ) {
    }
}
