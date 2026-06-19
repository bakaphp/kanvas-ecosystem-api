<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Reports\DataTransferObject;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

class ProfitAndLossData extends Data
{
    public function __construct(
        public readonly Carbon $period_start,
        public readonly Carbon $period_end,
        public readonly string $currency,
        public readonly ReportSection $revenue,
        public readonly ReportSection $cogs,
        public readonly float $gross_profit,
        public readonly ReportSection $operating_expenses,
        public readonly float $operating_income,
        public readonly ReportSection $other_income,
        public readonly ReportSection $other_expenses,
        public readonly float $net_income,
    ) {
    }
}
