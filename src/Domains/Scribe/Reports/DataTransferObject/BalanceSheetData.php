<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Reports\DataTransferObject;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

class BalanceSheetData extends Data
{
    public function __construct(
        public readonly Carbon $as_of,
        public readonly string $currency,
        public readonly ReportSection $assets,
        public readonly ReportSection $liabilities,
        public readonly ReportSection $equity,
        public readonly float $total_liabilities_and_equity,
        public readonly bool $is_balanced,
    ) {
    }
}
