<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Reports\DataTransferObject;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * @property DataCollection<ExpenseSummaryGroup> $by_category
 * @property DataCollection<ExpenseSummaryGroup> $by_employee
 * @property DataCollection<ExpenseSummaryGroup> $by_paid_by
 */
class ExpenseSummaryData extends Data
{
    public function __construct(
        public readonly Carbon $period_start,
        public readonly Carbon $period_end,
        public readonly string $currency,
        public readonly int $expense_count,
        public readonly float $grand_total,
        public readonly float $employee_paid_total,
        public readonly float $company_paid_total,
        /** @var DataCollection<ExpenseSummaryGroup> */
        public readonly DataCollection $by_category,
        /** @var DataCollection<ExpenseSummaryGroup> */
        public readonly DataCollection $by_employee,
        /** @var DataCollection<ExpenseSummaryGroup> */
        public readonly DataCollection $by_paid_by,
    ) {
    }
}
