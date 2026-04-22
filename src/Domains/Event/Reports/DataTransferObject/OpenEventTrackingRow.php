<?php

declare(strict_types=1);

namespace Kanvas\Event\Reports\DataTransferObject;

use Spatie\LaravelData\Data;

class OpenEventTrackingRow extends Data
{
    public function __construct(
        public int $event_version_id,
        public string $event_name,
        public ?string $event_date,
        public array $counts,
        public int $total_inscribed,
        public int $goal,
        public float $goal_percentage,
        public string $color,
    ) {
    }
}
