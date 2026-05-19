<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Pulse\DataTransferObject;

use Spatie\LaravelData\Data;

class PulseMetrics extends Data
{
    public function __construct(
        public readonly int $signals_count,
        public readonly int $signals_count_prior,
        public readonly int $actions_executed,
        public readonly int $actions_executed_prior,
        public readonly int $prevented_issues,
        public readonly int $prevented_issues_prior,
        public readonly ?float $system_confidence_pct,
        public readonly ?float $system_confidence_pct_prior,
    ) {
    }
}
