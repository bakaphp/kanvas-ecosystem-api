<?php

declare(strict_types=1);

namespace Tests\Scribe\Intelligence;

use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\QueryDueToEmployeesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\WhatDoesTheCompanyOweMeTool;
use Kanvas\Scribe\Expenses\Actions\RecordExpenseReimbursementAction;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Users\Models\Users;
use Tests\Scribe\ScribeTestCase;

final class ExpenseReimbursementToolsTest extends ScribeTestCase
{
    private const AS_OF = '2026-06-30';

    public function test_what_does_the_company_owe_me_reports_the_callers_own_position(): void
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

        $result = $this->oweMeTool($employee);

        $this->assertSame('owed', $result['status']);
        $this->assertEqualsWithDelta(350.00, $result['total_owed'], 0.005);
        $this->assertEqualsWithDelta(250.00, $result['current_month_total'], 0.005);
        $this->assertSame(2, $result['expense_count']);
        $this->assertSame('2026-04-10', $result['oldest_expense_date']);
        $this->assertSame(81, $result['days_outstanding']);
        $this->assertSame(['2026-04', '2026-06'], array_column($result['by_month'], 'month'));
    }

    public function test_what_does_the_company_owe_me_never_leaks_another_employees_expenses(): void
    {
        $alice = $this->seedTestEmployee('expense-employee');
        $bob = $this->seedTestEmployee('expense-employee');

        $this->approveTestExpense(900.00, ExpensePaidByEnum::EMPLOYEE_PERSONAL, $alice->getId());
        $this->approveTestExpense(75.00, ExpensePaidByEnum::EMPLOYEE_PERSONAL, $bob->getId());

        $result = $this->oweMeTool($bob);

        $this->assertEqualsWithDelta(75.00, $result['total_owed'], 0.005);
        $this->assertSame(1, $result['expense_count']);
    }

    public function test_what_does_the_company_owe_me_reports_nothing_owed_once_reimbursed(): void
    {
        $employee = $this->seedTestEmployee('expense-employee');

        $expense = $this->approveTestExpense(200.00, ExpensePaidByEnum::EMPLOYEE_PERSONAL, $employee->getId());
        new RecordExpenseReimbursementAction(
            expense: $expense,
            user: static::$cachedUser,
        )->execute();

        $result = $this->oweMeTool($employee);

        $this->assertSame('nothing_owed', $result['status']);
        $this->assertEqualsWithDelta(0.0, $result['total_owed'], 0.005);
    }

    public function test_what_does_the_company_owe_me_ignores_company_paid_spending(): void
    {
        $employee = $this->seedTestEmployee('expense-employee');

        $this->approveTestExpense(500.00, ExpensePaidByEnum::COMPANY_CARD, $employee->getId());

        $this->assertSame('nothing_owed', $this->oweMeTool($employee)['status']);
    }

    public function test_query_due_to_employees_reports_every_employee(): void
    {
        $alice = $this->seedTestEmployee('expense-employee');
        $bob = $this->seedTestEmployee('expense-employee');

        $this->approveTestExpense(350.00, ExpensePaidByEnum::EMPLOYEE_PERSONAL, $alice->getId());
        $this->approveTestExpense(75.00, ExpensePaidByEnum::EMPLOYEE_PERSONAL, $bob->getId());

        $result = new QueryDueToEmployeesTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(as_of: self::AS_OF);

        $this->assertSame(2, $result['employee_count']);
        $this->assertEqualsWithDelta(425.00, $result['grand_total'], 0.005);
        $this->assertSame($alice->getId(), $result['rows'][0]['users_id'], 'Highest amount owed leads.');
    }

    public function test_tools_fail_closed_without_tenant_context(): void
    {
        $this->assertSame('no_tenant_context', new QueryDueToEmployeesTool()->__invoke()['reason']);
        $this->assertSame('no_tenant_context', new WhatDoesTheCompanyOweMeTool()->__invoke()['reason']);
    }

    /**
     * @return array<string, mixed>
     */
    private function oweMeTool(Users $employee): array
    {
        return new WhatDoesTheCompanyOweMeTool()
            ->withContext($this->kanvasApp, $this->company, $employee)
            ->__invoke(as_of: self::AS_OF);
    }
}
