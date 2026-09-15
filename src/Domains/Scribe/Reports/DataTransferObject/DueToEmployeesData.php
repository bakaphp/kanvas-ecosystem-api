<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Reports\DataTransferObject;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * @property DataCollection<DueToEmployeesRow> $rows
 */
class DueToEmployeesData extends Data
{
    public function __construct(
        public readonly Carbon $as_of,
        public readonly string $currency,
        /** @var DataCollection<DueToEmployeesRow> */
        public readonly DataCollection $rows,
        public readonly int $employee_count,
        public readonly float $grand_total,
        public readonly float $current_month_total,
    ) {
    }
}
