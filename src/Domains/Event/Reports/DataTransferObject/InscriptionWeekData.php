<?php

declare(strict_types=1);

namespace Kanvas\Event\Reports\DataTransferObject;

use Spatie\LaravelData\Data;

class InscriptionWeekData extends Data
{
    public function __construct(
        public int $week,
        public array $counts,
        public int $total,
        public int $objective,
    ) {
    }
}
