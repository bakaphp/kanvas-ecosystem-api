<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\DataTransferObject;

use Spatie\LaravelData\Data;

class Vehicle extends Data
{
    public function __construct(
        public readonly bool $isNew,
        public readonly int $yearFrom,
        public readonly int $yearTo,
        public readonly string $make,
        public readonly string $model,
        public readonly string $trim,
        public readonly string $vin,
        public readonly string $stockNumber,
        public readonly int $priceFrom,
        public readonly int $priceTo,
        public readonly int $maxMileage,
        public readonly bool $isPrimary
    ) {
    }
}
