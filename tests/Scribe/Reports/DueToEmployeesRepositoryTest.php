<?php

declare(strict_types=1);

namespace Tests\Scribe\Reports;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Expenses\Actions\CreateExpenseAction;
use Kanvas\Scribe\Expenses\Actions\RecordExpenseReimbursementAction;
use Kanvas\Scribe\Expenses\DataTransferObject\Expense as ExpenseData;
use Kanvas\Scribe\Expenses\DataTransferObject\ExpenseLine as ExpenseLineData;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Reports\DataTransferObject\DueToEmployeesData;
use Kanvas\Scribe\Reports\DataTransferObject\DueToEmployeesRow;
use Kanvas\Scribe\Reports\Repositories\DueToEmployeesRepository;
use Kanvas\Scribe\Reports\Repositories\TrialBalanceRepository;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

final class DueToEmployeesRepositoryTest extends ScribeTestCase
{
    private const AS_OF = '2026-06-30';

    public function test_reports_only_approved_unreimbursed_employee_paid_expenses(): void
    {
        $employee = $this->seedTestEmployee('expense-employee');

        $this->approveTestExpense(500.00, ExpensePaidByEnum::COMPANY_CARD);
        $this->draftEmployeeExpense(400.00, $employee->getId());
        $this->approveTestExpense(300.00, ExpensePaidByEnum::EMPLOYEE_PERSONAL, $employee->getId());

        $reimbursed = $this->approveTestExpense(200.00, ExpensePaidByEnum::EMPLOYEE_PERSONAL, $employee->getId());
        new RecordExpenseReimbursementAction(
            expense: $reimbursed,
            user: static::$cachedUser,
        )->execute();

        $report = $this->generate();

        $this->assertSame(1, $report->employee_count);
        $this->assertEqualsWithDelta(300.00, $report->grand_total, 0.005);

        $row = $this->rowFor($report->rows, $employee->getId());
        $this->assertSame(1, $row->expense_count);
        $this->assertEqualsWithDelta(300.00, $row->total, 0.005);
    }

    public function test_groups_by_employee_and_month_ordered_by_amount_owed(): void
    {
        $alice = $this->seedTestEmployee('expense-employee');
        $bob = $this->seedTestEmployee('expense-employee');

        $this->approveTestExpense(
            100.00,
            ExpensePaidByEnum::EMPLOYEE_PERSONAL,
            $alice->getId(),
            '2026-04-10',
        );
        $this->approveTestExpense(
            250.00,
            ExpensePaidByEnum::EMPLOYEE_PERSONAL,
            $alice->getId(),
            '2026-06-15',
        );
        $this->approveTestExpense(
            75.00,
            ExpensePaidByEnum::EMPLOYEE_PERSONAL,
            $bob->getId(),
            '2026-06-20',
        );

        $report = $this->generate();

        $this->assertSame(2, $report->employee_count);
        $this->assertEqualsWithDelta(425.00, $report->grand_total, 0.005);

        $rows = $report->rows->toCollection();
        $this->assertSame($alice->getId(), $rows->first()->users_id, 'Rows sort by amount owed, descending.');

        $aliceRow = $this->rowFor($report->rows, $alice->getId());
        $this->assertSame(2, $aliceRow->expense_count);
        $this->assertEqualsWithDelta(350.00, $aliceRow->total, 0.005);

        $months = $aliceRow->months->toCollection();
        $this->assertSame(['2026-04', '2026-06'], $months->pluck('month')->all());
        $this->assertSame('April 2026', $months->first()->label);
        $this->assertEqualsWithDelta(100.00, $months->first()->total, 0.005);
        $this->assertEqualsWithDelta(250.00, $months->last()->total, 0.005);
    }

    public function test_current_month_total_and_days_outstanding_are_measured_against_as_of(): void
    {
        $employee = $this->seedTestEmployee('expense-employee');

        $this->approveTestExpense(
            100.00,
            ExpensePaidByEnum::EMPLOYEE_PERSONAL,
            $employee->getId(),
            '2026-04-10',
        );
        $this->approveTestExpense(
            250.00,
            ExpensePaidByEnum::EMPLOYEE_PERSONAL,
            $employee->getId(),
            '2026-06-15',
        );

        $report = $this->generate();
        $row = $this->rowFor($report->rows, $employee->getId());

        $this->assertEqualsWithDelta(250.00, $row->current_month_total, 0.005);
        $this->assertEqualsWithDelta(250.00, $report->current_month_total, 0.005);
        $this->assertSame('2026-04-10', $row->oldest_expense_date->toDateString());
        $this->assertSame(81, $row->days_outstanding);
    }

    public function test_scopes_to_a_single_employee_for_self_service(): void
    {
        $alice = $this->seedTestEmployee('expense-employee');
        $bob = $this->seedTestEmployee('expense-employee');

        $this->approveTestExpense(350.00, ExpensePaidByEnum::EMPLOYEE_PERSONAL, $alice->getId());
        $this->approveTestExpense(75.00, ExpensePaidByEnum::EMPLOYEE_PERSONAL, $bob->getId());

        $report = $this->generate($bob->getId());

        $this->assertSame(1, $report->employee_count);
        $this->assertEqualsWithDelta(75.00, $report->grand_total, 0.005);
        $this->assertSame($bob->getId(), $report->rows->toCollection()->first()->users_id);
    }

    public function test_employee_paid_expense_without_a_user_is_reported_as_unattributed(): void
    {
        $this->approveTestExpense(120.00, ExpensePaidByEnum::EMPLOYEE_PERSONAL);

        $report = $this->generate();
        $row = $report->rows->toCollection()->first();

        $this->assertNull($row->users_id, 'The liability is real even when nobody recorded who is owed.');
        $this->assertNull($row->user_name);
        $this->assertEqualsWithDelta(120.00, $report->grand_total, 0.005);
    }

    /**
     * The credit lands on the books at approval, so a receipt dated ahead of today is still owed —
     * cutting on expense_date instead would hide it from the employee while the GL still carries it.
     */
    public function test_counts_an_expense_dated_after_as_of_once_it_is_approved(): void
    {
        $employee = $this->seedTestEmployee('expense-employee');

        $this->approveTestExpense(
            90.00,
            ExpensePaidByEnum::EMPLOYEE_PERSONAL,
            $employee->getId(),
            '2026-08-20',
        );

        $report = $this->generate();

        $this->assertEqualsWithDelta(90.00, $report->grand_total, 0.005);
        $this->assertEqualsWithDelta($this->dueToEmployeesGlBalance(), $report->grand_total, 0.005);
    }

    public function test_grand_total_reconciles_with_the_due_to_employees_gl_balance(): void
    {
        $employee = $this->seedTestEmployee('expense-employee');

        $this->approveTestExpense(
            100.00,
            ExpensePaidByEnum::EMPLOYEE_PERSONAL,
            $employee->getId(),
            '2026-04-10',
        );
        $this->approveTestExpense(
            250.00,
            ExpensePaidByEnum::EMPLOYEE_PERSONAL,
            $employee->getId(),
            '2026-06-15',
        );
        $this->approveTestExpense(500.00, ExpensePaidByEnum::COMPANY_CARD);

        $settled = $this->approveTestExpense(200.00, ExpensePaidByEnum::EMPLOYEE_PERSONAL, $employee->getId());
        new RecordExpenseReimbursementAction(
            expense: $settled,
            user: static::$cachedUser,
        )->execute();

        $report = $this->generate();

        $this->assertEqualsWithDelta(
            $this->dueToEmployeesGlBalance(),
            $report->grand_total,
            0.005,
            'A drift here means an approval JE never posted, or a reimbursement flipped status without its JE.'
        );
    }

    private function generate(?int $usersId = null): DueToEmployeesData
    {
        return new DueToEmployeesRepository()->generate(
            app: $this->kanvasApp,
            company: $this->company,
            asOf: Carbon::parse(self::AS_OF),
            usersId: $usersId,
        );
    }

    private function dueToEmployeesGlBalance(): float
    {
        $trialBalance = new TrialBalanceRepository()->generate(
            $this->kanvasApp,
            $this->company,
            Carbon::parse(self::AS_OF),
        );

        $row = $trialBalance->rows
            ->toCollection()
            ->firstWhere('account_sub_type', AccountSubTypeEnum::DUE_TO_EMPLOYEES->value);

        return $row === null ? 0.0 : (float) $row->credit;
    }

    private function rowFor(DataCollection $rows, int $usersId): DueToEmployeesRow
    {
        $row = $rows->toCollection()->firstWhere('users_id', $usersId);
        $this->assertNotNull($row, "Expected a Due to Employees row for user {$usersId}.");

        return $row;
    }

    private function draftEmployeeExpense(float $amount, int $usersId): void
    {
        new CreateExpenseAction(
            data: new ExpenseData(
                app: $this->kanvasApp,
                company: $this->company,
                lines: new DataCollection(ExpenseLineData::class, [
                    new ExpenseLineData(
                        description: 'Never submitted',
                        amount_native: $amount,
                        expense_account_id: $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS),
                    ),
                ]),
                expense_date: Carbon::parse('2026-06-15'),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                paid_by: ExpensePaidByEnum::EMPLOYEE_PERSONAL,
                paid_by_users_id: $usersId,
            ),
            user: static::$cachedUser,
        )->execute();
    }
}
