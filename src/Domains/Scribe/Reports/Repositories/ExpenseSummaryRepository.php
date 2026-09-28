<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Reports\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Expenses\Models\ExpenseLine;
use Kanvas\Scribe\Reports\DataTransferObject\ExpenseSummaryData;
use Kanvas\Scribe\Reports\DataTransferObject\ExpenseSummaryGroup;
use Spatie\LaravelData\DataCollection;

/**
 * What the company spent over a period, cut three ways: by expense account (category), by the
 * employee who paid, and by how it was paid.
 *
 * Counts APPROVED expenses only, because approval is when the cost hits the books — a pending
 * expense is a claim, not spending, and including it would make the report disagree with the P&L.
 * Category totals come off the lines (one expense can straddle several accounts); the header totals
 * come off the expense, so the two agree only when every line of an expense shares one account.
 * Report them as what they are rather than forcing them to reconcile.
 */
class ExpenseSummaryRepository
{
    public function generate(
        AppInterface $app,
        CompanyInterface $company,
        Carbon $periodStart,
        Carbon $periodEnd,
        string $currency = 'USD',
    ): ExpenseSummaryData {
        $expenses = Expense::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->where('status', ExpenseStatusEnum::APPROVED->value)
            ->whereDate('expense_date', '>=', $periodStart)
            ->whereDate('expense_date', '<=', $periodEnd)
            ->with('paidByUser')
            ->get();

        $grandTotal = 0.0;
        $employeePaidTotal = 0.0;
        $byEmployee = [];
        $byPaidBy = [];

        foreach ($expenses as $expense) {
            $amount = (float) $expense->total_base;
            $grandTotal += $amount;

            $paidBy = $expense->paid_by->value;
            $byPaidBy[$paidBy] ??= ['label' => $paidBy, 'expense_count' => 0, 'total' => 0.0];
            $byPaidBy[$paidBy]['expense_count']++;
            $byPaidBy[$paidBy]['total'] += $amount;

            if ($expense->paid_by !== ExpensePaidByEnum::EMPLOYEE_PERSONAL) {
                continue;
            }

            $employeePaidTotal += $amount;

            $key = (string) ($expense->paid_by_users_id ?? 'unattributed');
            $byEmployee[$key] ??= [
                'label' => $expense->paidByUser?->displayname ?? 'Unattributed',
                'expense_count' => 0,
                'total' => 0.0,
            ];
            $byEmployee[$key]['expense_count']++;
            $byEmployee[$key]['total'] += $amount;
        }

        return new ExpenseSummaryData(
            period_start: $periodStart,
            period_end: $periodEnd,
            currency: $currency,
            expense_count: $expenses->count(),
            grand_total: $grandTotal,
            employee_paid_total: $employeePaidTotal,
            company_paid_total: $grandTotal - $employeePaidTotal,
            by_category: $this->categoryGroups($expenses->pluck('id')->all()),
            by_employee: $this->toGroups($byEmployee),
            by_paid_by: $this->toGroups($byPaidBy),
        );
    }

    /**
     * @param  array<int, int>  $expenseIds
     * @return DataCollection<ExpenseSummaryGroup>
     */
    private function categoryGroups(array $expenseIds): DataCollection
    {
        if ($expenseIds === []) {
            return new DataCollection(ExpenseSummaryGroup::class, []);
        }

        $lines = ExpenseLine::query()
            ->whereIn('expense_id', $expenseIds)
            ->with('expenseAccount')
            ->get();

        $byAccount = [];

        foreach ($lines as $line) {
            $accountId = (string) $line->expense_account_id;
            $byAccount[$accountId] ??= [
                'label' => $line->expenseAccount?->name ?? "Account {$accountId}",
                'expense_count' => 0,
                'total' => 0.0,
                // Counting rows here would make expense_count mean something different from the
                // same field on by_employee / by_paid_by — a 3-line expense on one account would
                // report as 3. Dedup on the expense so every group's count is a count of expenses.
                'expense_ids' => [],
            ];
            $byAccount[$accountId]['expense_ids'][(int) $line->expense_id] = true;
            $byAccount[$accountId]['total'] += (float) $line->amount_base + (float) $line->tax_amount_base;
        }

        foreach ($byAccount as $accountId => $bucket) {
            $byAccount[$accountId]['expense_count'] = count($bucket['expense_ids']);
            unset($byAccount[$accountId]['expense_ids']);
        }

        return $this->toGroups($byAccount);
    }

    /**
     * @param  array<string, array{label: string, expense_count: int, total: float}>  $buckets
     * @return DataCollection<ExpenseSummaryGroup>
     */
    private function toGroups(array $buckets): DataCollection
    {
        $groups = [];

        foreach ($buckets as $key => $bucket) {
            $groups[] = new ExpenseSummaryGroup(
                key: (string) $key,
                label: $bucket['label'],
                expense_count: $bucket['expense_count'],
                total: $bucket['total'],
            );
        }

        usort($groups, fn (ExpenseSummaryGroup $a, ExpenseSummaryGroup $b) => $b->total <=> $a->total);

        return new DataCollection(ExpenseSummaryGroup::class, $groups);
    }
}
