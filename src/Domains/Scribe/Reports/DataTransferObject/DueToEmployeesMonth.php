<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Reports\DataTransferObject;

use Spatie\LaravelData\Data;

class DueToEmployeesMonth extends Data
{
    public function __construct(
        public readonly string $month,
        public readonly string $label,
        public readonly int $expense_count,
        public readonly float $total,
    ) {
    }
}
