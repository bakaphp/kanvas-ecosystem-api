<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Stats\DataTransferObject;

use Spatie\LaravelData\Data;

class CapacityStats extends Data
{
    public function __construct(
        public int $maxCapacity,
        public int $availableCapacity,
        public int $occupiedCapacity,
        public float $occupancyPercentage
    ) {
    }

    public static function fromAggregation(?int $maxCapacity, ?int $availableCapacity): self
    {
        $maxCapacity = $maxCapacity ?? 0;
        $availableCapacity = $availableCapacity ?? 0;
        $occupiedCapacity = $maxCapacity - $availableCapacity;
        $occupancyPercentage = $maxCapacity > 0
            ? round(($occupiedCapacity / $maxCapacity) * 100, 2)
            : 0.0;

        return new self(
            maxCapacity: $maxCapacity,
            availableCapacity: $availableCapacity,
            occupiedCapacity: $occupiedCapacity,
            occupancyPercentage: $occupancyPercentage
        );
    }
}
