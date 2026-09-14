<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Reports\DataTransferObject;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * One employee's outstanding reimbursement position.
 *
 * `users_id` is null for an employee-paid expense that never got a `paid_by_users_id` — the company
 * owes the money but nobody recorded to whom. Those rows are kept rather than filtered so the report
 * still reconciles against the Due to Employees GL balance, and so the gap is visible to finance.
 *
 * @property DataCollection<DueToEmployeesMonth> $months
 */
class DueToEmployeesRow extends Data
{
    public function __construct(
        public readonly ?int $users_id,
        public readonly ?string $user_name,
        public readonly int $expense_count,
        public readonly float $total,
        public readonly float $current_month_total,
        public readonly Carbon $oldest_expense_date,
        public readonly int $days_outstanding,
        /** @var DataCollection<DueToEmployeesMonth> */
        public readonly DataCollection $months,
    ) {
    }
}
