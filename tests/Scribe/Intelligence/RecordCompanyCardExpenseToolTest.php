<?php

declare(strict_types=1);

namespace Tests\Scribe\Intelligence;

use Kanvas\Intelligence\Agents\Enums\ToolOutcomeEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\RecordCompanyCardExpenseTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\WhatDoesTheCompanyOweMeTool;
use Kanvas\Scribe\Expenses\Actions\ApproveExpenseAction;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseReimbursementStatusEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\Ledger\Models\JournalEntryLine;
use Kanvas\Users\Models\Users;
use Tests\Scribe\ScribeTestCase;

final class RecordCompanyCardExpenseToolTest extends ScribeTestCase
{
    public function test_files_the_charge_against_the_company_card_and_submits_it(): void
    {
        $employee = $this->seedTestEmployee('card-expense');

        $result = $this->record($employee, 3.79, 'Mouthwash for the office first-aid kit');

        $this->assertSame('submitted', $result['status'], (string) ($result['message'] ?? ''));
        $this->assertSame('company_card', $result['paid_by']);
        $this->assertEqualsWithDelta(3.79, $result['total'], 0.005);

        $expense = Expense::getById($result['expense_id']);
        $this->assertSame(ExpensePaidByEnum::COMPANY_CARD, $expense->paid_by);
        $this->assertSame(ExpenseStatusEnum::PENDING_APPROVAL, $expense->status);
        $this->assertSame(ExpenseReimbursementStatusEnum::NOT_APPLICABLE, $expense->reimbursement_status);
        $this->assertSame('CVS Pharmacy', $expense->vendor_display_name);
    }

    /**
     * `paid_by_users_id` is what the Due-to-Employees report pays out against, so a name in it on a
     * company-paid row is a claim waiting to be honoured. The filer belongs on `users_id`, which is
     * the question anyone actually asks of a card charge, and that one is still answered.
     */
    public function test_names_the_filer_without_making_them_a_creditor(): void
    {
        $employee = $this->seedTestEmployee('card-expense');

        $expense = Expense::getById($this->record($employee, 3.79, 'Office supplies')['expense_id']);

        $this->assertNull($expense->paid_by_users_id);
        $this->assertSame($employee->getId(), (int) $expense->users_id);
    }

    /**
     * The whole reason this is a separate tool from submit_my_expense. Filed through that one, the
     * same receipt leaves the company owing the employee; through this one it must never do so — not
     * before approval, and not after, which is where a wrong paid_by would finally surface as money.
     */
    public function test_the_company_never_ends_up_owing_the_person_who_filed_it(): void
    {
        $employee = $this->seedTestEmployee('card-expense');

        $expense = Expense::getById($this->record($employee, 145.50, 'Team lunch on the company card')['expense_id']);

        new ApproveExpenseAction(expense: $expense, approver: static::$cachedUser)->execute();

        $owed = new WhatDoesTheCompanyOweMeTool()
            ->withContext($this->kanvasApp, $this->company, $employee)
            ->__invoke();

        $this->assertSame('nothing_owed', $owed['status']);
    }

    /**
     * The ledger-level version of the same claim, and the one the bank feed reads: approval has to
     * credit Credit Card Liability, because that is the account the card's own statement rows land
     * against. Credit the wrong one and BankTransactionMatchService::findBookedExpense stops
     * recognising this charge as already booked, and the Mercury feed posts it a second time.
     */
    public function test_approval_credits_the_card_and_not_due_to_employees(): void
    {
        $employee = $this->seedTestEmployee('card-expense');

        $expense = Expense::getById($this->record($employee, 150.00, 'Annual SaaS renewal')['expense_id']);
        new ApproveExpenseAction(expense: $expense, approver: static::$cachedUser)->execute();

        $credits = JournalEntryLine::query()
            ->whereIn(
                'journal_entry_id',
                JournalEntry::query()
                    ->where('apps_id', $this->kanvasApp->getId())
                    ->where('source_type', 'expense')
                    ->where('source_id', $expense->getId())
                    ->select('id')
            )
            ->where('credit_base', '>', 0)
            ->pluck('credit_base', 'account_id');

        $cardAccount = $this->accountIdBySubType(AccountSubTypeEnum::CREDIT_CARD_LIABILITY);
        $employeeAccount = $this->accountIdBySubType(AccountSubTypeEnum::DUE_TO_EMPLOYEES);

        $this->assertEqualsWithDelta(150.00, (float) $credits[$cardAccount], 0.005);
        $this->assertArrayNotHasKey($employeeAccount, $credits);
    }

    public function test_splits_tax_out_of_the_total_rather_than_adding_to_it(): void
    {
        $employee = $this->seedTestEmployee('card-expense');

        $expense = Expense::getById($this->record($employee, 4.19, 'Mouthwash', taxAmount: 0.40)['expense_id']);

        $this->assertEqualsWithDelta(4.19, (float) $expense->total_native, 0.005);
        $this->assertEqualsWithDelta(3.79, (float) $expense->subtotal_native, 0.005);
        $this->assertEqualsWithDelta(0.40, (float) $expense->tax_native, 0.005);
    }

    /**
     * Receipts print the card every which way, and the digits are the entire value of the field —
     * they are what ties this row to one card on a statement carrying several.
     */
    public function test_keeps_the_card_digits_however_the_receipt_printed_them(): void
    {
        $employee = $this->seedTestEmployee('card-expense');

        $result = $this->record($employee, 4.19, 'Mouthwash', cardLast4: 'VISA ****0768');

        $this->assertSame('0768', $result['card_last4']);
        $this->assertSame('0768', Expense::getById($result['expense_id'])->metadata['card_last4']);
    }

    public function test_drops_a_card_reference_it_cannot_read(): void
    {
        $employee = $this->seedTestEmployee('card-expense');

        $result = $this->record($employee, 4.19, 'Mouthwash', cardLast4: 'the blue one');

        $this->assertArrayNotHasKey('card_last4', $result);
        $this->assertArrayNotHasKey('card_last4', Expense::getById($result['expense_id'])->metadata ?? []);
    }

    public function test_attaches_the_receipt_when_one_is_given(): void
    {
        $employee = $this->seedTestEmployee('card-expense');
        $receipt = $this->createFilesystemRow();

        $result = $this->record($employee, 4.19, 'Mouthwash', filesystemId: (int) $receipt->getKey());

        $this->assertArrayNotHasKey('attachment_warning', $result);
        $this->assertSame(1, Expense::getById($result['expense_id'])->receipts()->count());
    }

    public function test_reports_a_missing_receipt_without_losing_the_expense(): void
    {
        $employee = $this->seedTestEmployee('card-expense');

        $result = $this->record($employee, 4.19, 'Mouthwash', filesystemId: 99999999);

        $this->assertSame('submitted', $result['status']);
        $this->assertStringContainsString('99999999', $result['attachment_warning']);
    }

    /**
     * This one books company money rather than a debt to a person, so the Due-to-Employees argument
     * that guards submit_my_expense does not apply. The guard still has to hold, for the other
     * reason: an agent turn is steered by whatever it last read, and this agent reads inbound email.
     */
    public function test_refuses_to_book_company_money_on_an_agents_own_turn(): void
    {
        $agentUser = $this->seedTestEmployee('agent-identity');
        $agent = Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['name' => 'Polly', 'user_id' => $agentUser->getId()]);

        $result = new RecordCompanyCardExpenseTool()
            ->withContext(
                $this->kanvasApp,
                $this->company,
                $agentUser,
                $agent,
            )
            ->__invoke(amount: 4.19, description: 'A charge nobody asked for');

        $this->assertFalse($result['success']);
        $this->assertSame('agent_caller', $result['status']);
        $this->assertSame(ToolOutcomeEnum::DENIED->value, $result['outcome']);
        $this->assertSame(
            0,
            Expense::query()
                ->where('apps_id', $this->kanvasApp->getId())
                ->where('users_id', $agentUser->getId())
                ->count(),
        );
    }

    public function test_refuses_an_amount_that_is_not_a_charge(): void
    {
        $result = $this->record($this->seedTestEmployee('card-expense'), 0.0, 'Nothing');

        $this->assertSame('invalid_amount', $result['status']);
        $this->assertFalse($result['success']);
        $this->assertSame(ToolOutcomeEnum::INVALID_ARGS->value, $result['outcome']);
    }

    public function test_fails_closed_without_a_tenant(): void
    {
        $result = new RecordCompanyCardExpenseTool()->__invoke(amount: 4.19, description: 'x');

        $this->assertSame('no_tenant_context', $result['reason']);
    }

    /**
     * @return array<string, mixed>
     */
    private function record(
        Users $employee,
        float $amount,
        string $description,
        ?float $taxAmount = null,
        ?string $cardLast4 = null,
        ?int $filesystemId = null,
    ): array {
        return new RecordCompanyCardExpenseTool()
            ->withContext($this->kanvasApp, $this->company, $employee)
            ->__invoke(
                amount: $amount,
                description: $description,
                expense_date: '2026-06-15',
                merchant: 'CVS Pharmacy',
                tax_amount: $taxAmount,
                card_last4: $cardLast4,
                filesystem_id: $filesystemId,
            );
    }
}
