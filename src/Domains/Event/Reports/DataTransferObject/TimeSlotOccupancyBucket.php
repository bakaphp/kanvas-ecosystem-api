<?php

declare(strict_types=1);

namespace Kanvas\Event\Reports\DataTransferObject;

use Spatie\LaravelData\Data;

class TimeSlotOccupancyBucket extends Data
{
    public function __construct(
        public readonly string $label,
        public readonly int $capacity,
        public readonly int $booked,
        public readonly float $occupancy_percentage,
        public readonly int $slots_count,
    ) {
    }

    public static function fromTotals(string $label, int $capacity, int $booked, int $slotsCount): self
    {
        return new self(
            label: $label,
            capacity: $capacity,
            booked: $booked,
            occupancy_percentage: $capacity > 0 ? round($booked / $capacity * 100, 2) : 0.0,
            slots_count: $slotsCount,
        );
    }
}
