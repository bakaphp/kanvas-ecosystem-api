<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Reports\DataTransferObject;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * @property DataCollection<RevenueRow> $rows
 */
class RevenueReportData extends Data
{
    public function __construct(
        public readonly Carbon $period_start,
        public readonly Carbon $period_end,
        public readonly string $currency,
        public readonly string $group_by,
        /** @var DataCollection<RevenueRow> */
        public readonly DataCollection $rows,
        public readonly float $total_gross_revenue,
        public readonly float $total_discounts,
        public readonly float $total_net_revenue,
        public readonly int $total_invoice_count,
    ) {
    }
}
