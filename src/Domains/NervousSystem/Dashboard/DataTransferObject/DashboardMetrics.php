<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Dashboard\DataTransferObject;

use Spatie\LaravelData\Data;

class DashboardMetrics extends Data
{
    public function __construct(
        public readonly ?float $workforce_leverage,
        public readonly ?float $workforce_leverage_prior,
        public readonly ?float $human_equivalents,
        public readonly int $accomplishments_count,
        public readonly int $accomplishments_count_prior,
        public readonly int $mistakes_caught_count,
        public readonly int $mistakes_auto_corrected,
        public readonly int $mistakes_escalated,
        public readonly ?float $time_recovered_hours,
        public readonly ?float $time_recovered_hours_prior,
        public readonly ?int $value_delivered_cents,
        public readonly ?int $value_delivered_cents_prior,
    ) {
    }
}
