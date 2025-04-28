<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\DataTransferObject;

use Spatie\LaravelData\Data;

class TradeIn extends Data
{
    public function __construct(
        public readonly int $year,
        public readonly string $make,
        public readonly string $model,
        public readonly string $trim,
        public readonly string $vin,
        public readonly int $estimatedMileage,
        public readonly string $interiorColor,
        public readonly string $exteriorColor
    ) {
    }
}
