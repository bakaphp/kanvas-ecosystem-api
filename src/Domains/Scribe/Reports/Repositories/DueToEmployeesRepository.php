<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Reports\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseReimbursementStatusEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Reports\DataTransferObject\DueToEmployeesData;
use Kanvas\Scribe\Reports\DataTransferObject\DueToEmployeesMonth;
use Kanvas\Scribe\Reports\DataTransferObject\DueToEmployeesRow;
use Spatie\LaravelData\DataCollection;

/**
 * What the company still owes its own staff for expenses they paid out of pocket — the Due to
 * Employees counterpart to ArAgingRepository.
 *
 * Reads the Expense table rather than the ledger for the same reason ArAgingRepository reads
 * Invoice: the operational document is the source of truth for what is outstanding, and the GL is
 * its accounting projection. DueToEmployeesRepositoryTest asserts the two agree.
 *
 * Grouped by MONTH, not by 30/60/90 aging buckets. An expense has no due date, and reimbursement is
 * paid in periodic batches against a fiscal period — so a month bucket is stable (an expense filed
 * in June stays in June) and maps onto an actual payment run, where a sliding aging bucket would
 * not. The neglect signal that aging exists to surface is carried per row by `days_outstanding`.
 *
 * `asOf` cuts on `approved_at`, not `expense_date`: the liability begins when the approval JE posts,
 * so this matches what TrialBalanceRepository sees and keeps the two reconcilable. Cutting on
 * expense_date instead would hide an expense dated ahead of today whose credit is already on the
 * books. Months still group by expense_date — that is the month the employee means.
 */
class DueToEmployeesRepository
{
    public function generate(
        AppInterface $app,
        CompanyInterface $company,
        Carbon $asOf,
        ?int $usersId = null,
        string $currency = 'USD',
    ): DueToEmployeesData {
        $query = Expense::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->where('paid_by', ExpensePaidByEnum::EMPLOYEE_PERSONAL->value)
            ->where('status', ExpenseStatusEnum::APPROVED->value)
            ->whereIn('reimbursement_status', [
                ExpenseReimbursementStatusEnum::PENDING->value,
                ExpenseReimbursementStatusEnum::APPROVED->value,
            ])
            ->whereDate('approved_at', '<=', $asOf)
            ->with('paidByUser');

        if ($usersId !== null) {
            $query->where('paid_by_users_id', $usersId);
        }

        $currentMonth = $asOf->format('Y-m');
        $byEmployee = [];

        foreach ($query->get() as $expense) {
            $employeeId = $expense->paid_by_users_id !== null ? (int) $expense->paid_by_users_id : null;
            $key = (string) ($employeeId ?? 'unattributed');
            $amount = (float) $expense->total_base;
            $month = $expense->expense_date->format('Y-m');

            if (! isset($byEmployee[$key])) {
                $byEmployee[$key] = [
                    'users_id' => $employeeId,
                    'user_name' => $expense->paidByUser?->displayname,
                    'expense_count' => 0,
                    'total' => 0.0,
                    'current_month_total' => 0.0,
                    'oldest_expense_date' => $expense->expense_date,
                    'months' => [],
                ];
            }

            $byEmployee[$key]['expense_count']++;
            $byEmployee[$key]['total'] += $amount;

            if ($month === $currentMonth) {
                $byEmployee[$key]['current_month_total'] += $amount;
            }

            if ($expense->expense_date->lt($byEmployee[$key]['oldest_expense_date'])) {
                $byEmployee[$key]['oldest_expense_date'] = $expense->expense_date;
            }

            if (! isset($byEmployee[$key]['months'][$month])) {
                $byEmployee[$key]['months'][$month] = [
                    'label' => $expense->expense_date->format('F Y'),
                    'expense_count' => 0,
                    'total' => 0.0,
                ];
            }

            $byEmployee[$key]['months'][$month]['expense_count']++;
            $byEmployee[$key]['months'][$month]['total'] += $amount;
        }

        $rows = [];
        $grandTotal = 0.0;
        $currentMonthTotal = 0.0;

        foreach ($byEmployee as $employee) {
            $months = $employee['months'];
            ksort($months);

            $monthRows = [];
            foreach ($months as $month => $bucket) {
                $monthRows[] = new DueToEmployeesMonth(
                    month: (string) $month,
                    label: $bucket['label'],
                    expense_count: $bucket['expense_count'],
                    total: $bucket['total'],
                );
            }

            $oldest = $employee['oldest_expense_date'];

            $rows[] = new DueToEmployeesRow(
                users_id: $employee['users_id'],
                user_name: $employee['user_name'],
                expense_count: $employee['expense_count'],
                total: $employee['total'],
                current_month_total: $employee['current_month_total'],
                oldest_expense_date: $oldest,
                days_outstanding: (int) $oldest->diffInDays($asOf),
                months: new DataCollection(DueToEmployeesMonth::class, $monthRows),
            );

            $grandTotal += $employee['total'];
            $currentMonthTotal += $employee['current_month_total'];
        }

        usort($rows, fn (DueToEmployeesRow $a, DueToEmployeesRow $b) => $b->total <=> $a->total);

        return new DueToEmployeesData(
            as_of: $asOf,
            currency: $currency,
            rows: new DataCollection(DueToEmployeesRow::class, $rows),
            employee_count: count($rows),
            grand_total: $grandTotal,
            current_month_total: $currentMonthTotal,
        );
    }
}
