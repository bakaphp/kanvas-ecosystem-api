<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Reports\DataTransferObject;

use Spatie\LaravelData\Data;

class ExpenseSummaryGroup extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly int $expense_count,
        public readonly float $total,
    ) {
    }
}
