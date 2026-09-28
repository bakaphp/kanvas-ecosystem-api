<?php

declare(strict_types=1);

namespace Tests\Scribe\Intelligence;

use Kanvas\Intelligence\Agents\Enums\ToolOutcomeEnum;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CategorizeExpenseTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryExpenseReportTool;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Tests\Scribe\ScribeTestCase;

final class ExpenseReportingToolsTest extends ScribeTestCase
{
    private const PERIOD_START = '2026-06-01';
    private const PERIOD_END = '2026-06-30';

    public function test_expense_report_splits_employee_paid_from_company_paid(): void
    {
        $employee = $this->seedTestEmployee('expense-report');

        $this->approveTestExpense(300.00, ExpensePaidByEnum::EMPLOYEE_PERSONAL, $employee->getId());
        $this->approveTestExpense(500.00, ExpensePaidByEnum::COMPANY_CARD);

        $result = $this->report();

        $this->assertSame('ok', $result['outcome']);
        $this->assertSame(2, $result['expense_count']);
        $this->assertEqualsWithDelta(800.00, $result['grand_total'], 0.005);
        $this->assertEqualsWithDelta(300.00, $result['employee_paid_total'], 0.005);
        $this->assertEqualsWithDelta(500.00, $result['company_paid_total'], 0.005);
    }

    public function test_expense_report_groups_by_category_and_employee(): void
    {
        $employee = $this->seedTestEmployee('expense-report');
        $this->approveTestExpense(300.00, ExpensePaidByEnum::EMPLOYEE_PERSONAL, $employee->getId());

        $result = $this->report();

        $this->assertSame('Travel & Meals', $result['by_category'][0]['label']);
        $this->assertEqualsWithDelta(300.00, $result['by_category'][0]['total'], 0.005);
        $this->assertSame((string) $employee->getId(), $result['by_employee'][0]['key']);
        $this->assertSame(ExpensePaidByEnum::EMPLOYEE_PERSONAL->value, $result['by_paid_by'][0]['key']);
    }

    /**
     * An expense that is filed but not approved is a claim, not spending — counting it would put the
     * report out of step with the P&L, which only sees the approval JE.
     */
    public function test_expense_report_ignores_expenses_awaiting_approval(): void
    {
        $employee = $this->seedTestEmployee('expense-report');
        $this->draftTestExpense(300.00, $employee->getId());

        $result = $this->report();

        $this->assertSame(0, $result['expense_count']);
        $this->assertSame(ToolOutcomeEnum::NOOP->value, $result['outcome']);
    }

    /**
     * by_category shares the `expense_count` name with by_employee and by_paid_by, so it has to
     * mean the same thing. Counting the underlying lines would report this single three-line
     * expense as three.
     */
    public function test_expense_report_category_count_counts_expenses_not_lines(): void
    {
        $employee = $this->seedTestEmployee('expense-report');
        $this->approveTestExpense(
            300.00,
            ExpensePaidByEnum::EMPLOYEE_PERSONAL,
            $employee->getId(),
            lineAmounts: [100.00, 100.00, 100.00],
        );

        $result = $this->report();

        $this->assertSame('Travel & Meals', $result['by_category'][0]['label']);
        $this->assertSame(1, $result['by_category'][0]['expense_count']);
        $this->assertSame($result['expense_count'], $result['by_category'][0]['expense_count']);
        $this->assertEqualsWithDelta(300.00, $result['by_category'][0]['total'], 0.005);
    }

    public function test_categorize_expense_moves_every_line_to_the_named_account(): void
    {
        $employee = $this->seedTestEmployee('expense-report');
        $draft = $this->draftTestExpense(120.00, $employee->getId());
        $software = $this->accountIdBySubType(AccountSubTypeEnum::SOFTWARE_SUBSCRIPTIONS);

        $result = $this->categorize($draft->getId(), (string) Account::getById($software)->name);

        $this->assertSame('recategorized', $result['status']);
        $this->assertSame($software, (int) $draft->refresh()->lines->first()->expense_account_id);
    }

    public function test_categorize_expense_accepts_an_account_number(): void
    {
        $employee = $this->seedTestEmployee('expense-report');
        $draft = $this->draftTestExpense(120.00, $employee->getId());
        $software = Account::getById($this->accountIdBySubType(AccountSubTypeEnum::SOFTWARE_SUBSCRIPTIONS));

        $result = $this->categorize($draft->getId(), (string) $software->account_number);

        $this->assertSame('recategorized', $result['status']);
    }

    public function test_categorize_expense_refuses_an_approved_expense(): void
    {
        $employee = $this->seedTestEmployee('expense-report');
        $approved = $this->approveTestExpense(120.00, ExpensePaidByEnum::EMPLOYEE_PERSONAL, $employee->getId());
        $travel = $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS);

        $result = $this->categorize($approved->getId(), (string) Account::getById($travel)->account_number);

        $this->assertFalse($result['success']);
        $this->assertSame('not_draft', $result['status']);
    }

    public function test_categorize_expense_rejects_an_unknown_account(): void
    {
        $employee = $this->seedTestEmployee('expense-report');
        $draft = $this->draftTestExpense(120.00, $employee->getId());

        $result = $this->categorize($draft->getId(), 'Not A Real Account');

        $this->assertFalse($result['success']);
        $this->assertSame(ToolOutcomeEnum::INVALID_ARGS->value, $result['outcome']);
    }

    /**
     * @return array<string, mixed>
     */
    private function report(): array
    {
        return new QueryExpenseReportTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(period_start: self::PERIOD_START, period_end: self::PERIOD_END);
    }

    /**
     * @return array<string, mixed>
     */
    private function categorize(int $expenseId, string $account): array
    {
        return new CategorizeExpenseTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(expense_id: $expenseId, account: $account);
    }
}
