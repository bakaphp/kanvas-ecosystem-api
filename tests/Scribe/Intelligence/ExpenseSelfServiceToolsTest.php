<?php

declare(strict_types=1);

namespace Tests\Scribe\Intelligence;

use Kanvas\Intelligence\Agents\Enums\ToolOutcomeEnum;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CancelMyExpenseTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListMyExpensesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\RecordExpenseReimbursementTool;
use Kanvas\Scribe\Expenses\Actions\SubmitExpenseForApprovalAction;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseReimbursementStatusEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Users\Models\Users;
use Tests\Scribe\ScribeTestCase;

final class ExpenseSelfServiceToolsTest extends ScribeTestCase
{
    public function test_list_my_expenses_returns_only_the_callers_own(): void
    {
        $alice = $this->seedTestEmployee('expense-selfservice');
        $bob = $this->seedTestEmployee('expense-selfservice');

        $this->approveTestExpense(350.00, ExpensePaidByEnum::EMPLOYEE_PERSONAL, $alice->getId());
        $this->approveTestExpense(75.00, ExpensePaidByEnum::EMPLOYEE_PERSONAL, $bob->getId());

        $result = $this->listFor($bob);

        $this->assertSame('found', $result['status']);
        $this->assertSame(1, $result['count']);
        $this->assertEqualsWithDelta(75.00, $result['expenses'][0]['total'], 0.005);
    }

    public function test_list_my_expenses_shows_states_that_are_not_yet_approved(): void
    {
        $employee = $this->seedTestEmployee('expense-selfservice');
        $draft = $this->draftTestExpense(60.00, $employee->getId());

        $result = $this->listFor($employee);

        $this->assertSame($draft->getId(), $result['expenses'][0]['expense_id']);
        $this->assertSame(ExpenseStatusEnum::DRAFT->value, $result['expenses'][0]['status']);
    }

    public function test_list_my_expenses_labels_an_empty_result_a_noop(): void
    {
        $result = $this->listFor($this->seedTestEmployee('expense-selfservice'));

        $this->assertSame('none_found', $result['status']);
        $this->assertSame(ToolOutcomeEnum::NOOP->value, $result['outcome']);
    }

    public function test_cancel_my_expense_withdraws_an_unapproved_one(): void
    {
        $employee = $this->seedTestEmployee('expense-selfservice');
        $draft = $this->draftTestExpense(60.00, $employee->getId());

        $result = $this->cancel($employee, $draft->getId());

        $this->assertSame('withdrawn', $result['status']);
        $this->assertSame(ExpenseStatusEnum::VOIDED, Expense::getById($draft->getId())->status);
    }

    /**
     * Tenant scoping alone does not stop a colleague — both employees are in the same company, so
     * without the ownership gate one could void the other's expense by guessing an id.
     */
    public function test_cancel_my_expense_refuses_someone_elses(): void
    {
        $alice = $this->seedTestEmployee('expense-selfservice');
        $bob = $this->seedTestEmployee('expense-selfservice');
        $hers = $this->draftTestExpense(60.00, $alice->getId());

        $result = $this->cancel($bob, $hers->getId());

        $this->assertFalse($result['success']);
        $this->assertSame(ToolOutcomeEnum::DENIED->value, $result['outcome']);
        $this->assertSame(ExpenseStatusEnum::DRAFT, Expense::getById($hers->getId())->status);
    }

    public function test_cancel_my_expense_refuses_an_approved_one(): void
    {
        $employee = $this->seedTestEmployee('expense-selfservice');
        $approved = $this->approveTestExpense(120.00, ExpensePaidByEnum::EMPLOYEE_PERSONAL, $employee->getId());

        $result = $this->cancel($employee, $approved->getId());

        $this->assertFalse($result['success']);
        $this->assertSame('already_approved', $result['status']);
        $this->assertSame(ExpenseStatusEnum::APPROVED, Expense::getById($approved->getId())->status);
    }

    /**
     * The state submit_my_expense actually leaves an expense in. ExpenseStateMachineService allows
     * VOIDED only from DRAFT or APPROVED, so this is a refusal — it must read as one rather than as
     * an uncaught throw reported to Sentry and narrated to the model as "an external service failed".
     */
    public function test_cancel_my_expense_refuses_one_already_sent_for_approval(): void
    {
        $employee = $this->seedTestEmployee('expense-selfservice');
        $pending = new SubmitExpenseForApprovalAction(
            expense: $this->draftTestExpense(90.00, $employee->getId()),
            user: $employee,
        )->execute();

        $result = $this->cancel($employee, $pending->getId());

        $this->assertFalse($result['success']);
        $this->assertSame('awaiting_approval', $result['status']);
        $this->assertSame(ToolOutcomeEnum::DENIED->value, $result['outcome']);
        $this->assertSame(ExpenseStatusEnum::PENDING_APPROVAL, Expense::getById($pending->getId())->status);
    }

    public function test_record_reimbursement_clears_the_liability(): void
    {
        $employee = $this->seedTestEmployee('expense-selfservice');
        $approved = $this->approveTestExpense(200.00, ExpensePaidByEnum::EMPLOYEE_PERSONAL, $employee->getId());

        $result = $this->reimburse($approved->getId());

        $this->assertSame('reimbursed', $result['status']);
        $this->assertSame(
            ExpenseReimbursementStatusEnum::PAID,
            Expense::getById($approved->getId())->reimbursement_status,
        );
    }

    public function test_record_reimbursement_explains_an_unapproved_expense_rather_than_reporting_it(): void
    {
        $employee = $this->seedTestEmployee('expense-selfservice');
        $draft = $this->draftTestExpense(200.00, $employee->getId());

        $result = $this->reimburse($draft->getId());

        $this->assertFalse($result['success']);
        $this->assertSame('not_ready', $result['status']);
        $this->assertSame(ToolOutcomeEnum::DENIED->value, $result['outcome']);
    }

    public function test_record_reimbursement_is_a_noop_the_second_time(): void
    {
        $employee = $this->seedTestEmployee('expense-selfservice');
        $approved = $this->approveTestExpense(200.00, ExpensePaidByEnum::EMPLOYEE_PERSONAL, $employee->getId());

        $this->reimburse($approved->getId());
        $result = $this->reimburse($approved->getId());

        $this->assertSame('already_reimbursed', $result['status']);
        $this->assertSame(ToolOutcomeEnum::NOOP->value, $result['outcome']);
    }

    /**
     * @return array<string, mixed>
     */
    private function listFor(Users $employee): array
    {
        return new ListMyExpensesTool()
            ->withContext($this->kanvasApp, $this->company, $employee)
            ->__invoke();
    }

    /**
     * @return array<string, mixed>
     */
    private function cancel(Users $employee, int $expenseId): array
    {
        return new CancelMyExpenseTool()
            ->withContext($this->kanvasApp, $this->company, $employee)
            ->__invoke(expense_id: $expenseId, reason: 'duplicate');
    }

    /**
     * @return array<string, mixed>
     */
    private function reimburse(int $expenseId): array
    {
        return new RecordExpenseReimbursementTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(expense_id: $expenseId);
    }
}
