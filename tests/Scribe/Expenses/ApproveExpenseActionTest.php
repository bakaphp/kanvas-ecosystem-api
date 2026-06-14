<?php

declare(strict_types=1);

namespace Tests\Scribe\Expenses;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\Approvals\Enums\ApprovalQueueStatusEnum;
use Kanvas\Scribe\Approvals\Models\ApprovalQueueItem;
use Kanvas\Scribe\Expenses\Actions\ApproveExpenseAction;
use Kanvas\Scribe\Expenses\Actions\CreateExpenseAction;
use Kanvas\Scribe\Expenses\Actions\RecordExpenseReimbursementAction;
use Kanvas\Scribe\Expenses\Actions\SubmitExpenseForApprovalAction;
use Kanvas\Scribe\Expenses\DataTransferObject\ExpenseData;
use Kanvas\Scribe\Expenses\DataTransferObject\ExpenseLineData;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseReimbursementStatusEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Enums\FiscalPeriodStatusEnum;
use Kanvas\Scribe\Ledger\Enums\JournalEntryStatusEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\FiscalPeriod;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\Ledger\Services\ChartOfAccountsSeederService;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

/**
 * Verifies the three JE shapes ExpenseJournalEntryComposer produces depending on `paid_by`, plus the
 * reimbursement cycle for employee-paid expenses (plan §11.4 — Juan's hotel).
 */
class ApproveExpenseActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'accounting'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();

        new ChartOfAccountsSeederService()->seedUsDefault($this->kanvasApp->getId(), $this->company->getId());

        FiscalPeriod::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'status' => FiscalPeriodStatusEnum::OPEN,
        ]);
    }

    public function test_employee_paid_expense_posts_due_to_employees_credit(): void
    {
        // Juan pays $500 for hotel
        $expense = $this->createAndSubmit(
            paidBy: ExpensePaidByEnum::EMPLOYEE_PERSONAL,
            paidByUsersId: static::$cachedUser->getId(),
            amount: 500.00,
        );

        // Reimbursement starts as PENDING
        $this->assertSame(ExpenseReimbursementStatusEnum::PENDING, $expense->reimbursement_status);

        $approved = new ApproveExpenseAction(
            expense: $expense,
            approver: static::$cachedUser,
        )->execute();

        // State
        $this->assertSame(ExpenseStatusEnum::APPROVED, $approved->status);
        $this->assertSame('1', $approved->expense_number, 'Number allocated on approval.');
        $this->assertSame(
            ExpenseReimbursementStatusEnum::APPROVED,
            $approved->reimbursement_status,
            'Employee-paid expense reimbursement_status flips PENDING → APPROVED on approval.'
        );

        // JE shape: DR Travel & Meals $500 / CR Due to Employees $500
        $je = JournalEntry::query()
            ->where('source_type', 'expense')
            ->where('source_id', $approved->id)
            ->where('status', JournalEntryStatusEnum::POSTED->value)
            ->first();
        $this->assertNotNull($je);

        $je->load('lines');
        $this->assertCount(2, $je->lines);
        $this->assertEquals(500.0, $je->lines->sum('debit_base'));
        $this->assertEquals(500.0, $je->lines->sum('credit_base'));

        $expenseAccountId = $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS);
        $dueToEmployeesId = $this->accountIdBySubType(AccountSubTypeEnum::DUE_TO_EMPLOYEES);

        $debitLine = $je->lines->firstWhere('account_id', $expenseAccountId);
        $this->assertNotNull($debitLine, 'DR Travel & Meals line.');
        $this->assertEquals(500.0, (float) $debitLine->debit_native);

        $creditLine = $je->lines->firstWhere('account_id', $dueToEmployeesId);
        $this->assertNotNull($creditLine, 'CR Due to Employees line.');
        $this->assertEquals(500.0, (float) $creditLine->credit_native);
        $this->assertSame(
            'users',
            $creditLine->vendor_billable_type,
            'Credit line tagged with vendor_billable_type=users for sub-ledger by user.'
        );
        $this->assertEquals(static::$cachedUser->getId(), $creditLine->vendor_billable_id);

        // Approval queue item closed as APPROVED
        $queueItem = ApprovalQueueItem::query()
            ->where('target_type', 'expense')
            ->where('target_id', $approved->id)
            ->first();
        $this->assertNotNull($queueItem);
        $this->assertSame(ApprovalQueueStatusEnum::APPROVED, $queueItem->status);
        $this->assertEquals(static::$cachedUser->getId(), $queueItem->approved_by_users_id);
    }

    public function test_company_card_expense_credits_credit_card_liability(): void
    {
        $expense = $this->createAndSubmit(
            paidBy: ExpensePaidByEnum::COMPANY_CARD,
            paidByUsersId: null,
            amount: 87.50,
            expenseAccountSubType: AccountSubTypeEnum::OFFICE_SUPPLIES,
        );

        $approved = new ApproveExpenseAction(
            expense: $expense,
            approver: static::$cachedUser,
        )->execute();

        $this->assertSame(
            ExpenseReimbursementStatusEnum::NOT_APPLICABLE,
            $approved->reimbursement_status,
            'Company-card expenses never generate a reimbursement obligation.'
        );

        $je = JournalEntry::query()
            ->where('source_type', 'expense')
            ->where('source_id', $approved->id)
            ->first();

        $je->load('lines');
        $creditCardId = $this->accountIdBySubType(AccountSubTypeEnum::CREDIT_CARD_LIABILITY);
        $creditLine = $je->lines->firstWhere('account_id', $creditCardId);
        $this->assertNotNull($creditLine, 'Credit line should point at Credit Card Liability.');
        $this->assertEquals(87.50, (float) $creditLine->credit_native);
    }

    public function test_direct_bank_transfer_expense_credits_cash(): void
    {
        $expense = $this->createAndSubmit(
            paidBy: ExpensePaidByEnum::COMPANY_BANK_TRANSFER,
            paidByUsersId: null,
            amount: 5000.00,
            expenseAccountSubType: AccountSubTypeEnum::LEGAL_FEES,
        );

        $approved = new ApproveExpenseAction(
            expense: $expense,
            approver: static::$cachedUser,
        )->execute();

        $je = JournalEntry::query()
            ->where('source_type', 'expense')
            ->where('source_id', $approved->id)
            ->first();

        $je->load('lines');
        $cashId = $this->accountIdBySubType(AccountSubTypeEnum::CASH_CHECKING);
        $creditLine = $je->lines->firstWhere('account_id', $cashId);
        $this->assertNotNull($creditLine, 'Credit line should point at Cash (system fallback when no bank_account set).');
        $this->assertEquals(5000.0, (float) $creditLine->credit_native);
    }

    public function test_reimbursement_cycle_clears_due_to_employees_liability(): void
    {
        $expense = $this->createAndSubmit(
            paidBy: ExpensePaidByEnum::EMPLOYEE_PERSONAL,
            paidByUsersId: static::$cachedUser->getId(),
            amount: 500.00,
        );

        $approved = new ApproveExpenseAction(
            expense: $expense,
            approver: static::$cachedUser,
        )->execute();

        // Now reimburse — MCTekk ACHs Juan
        $reimbursed = new RecordExpenseReimbursementAction(
            expense: $approved,
            user: static::$cachedUser,
            reimbursementPaymentId: null,
        )->execute();

        $this->assertSame(ExpenseReimbursementStatusEnum::PAID, $reimbursed->reimbursement_status);
        $this->assertNotNull($reimbursed->reimbursed_at);

        // A SECOND JE should exist — sourceType='expense_reimbursement'
        $reimbursementJe = JournalEntry::query()
            ->where('source_type', 'expense_reimbursement')
            ->where('source_id', $reimbursed->id)
            ->first();
        $this->assertNotNull($reimbursementJe);

        $reimbursementJe->load('lines');
        $this->assertCount(2, $reimbursementJe->lines);
        $this->assertEquals(500.0, $reimbursementJe->lines->sum('debit_base'));
        $this->assertEquals(500.0, $reimbursementJe->lines->sum('credit_base'));

        $dueToEmployeesId = $this->accountIdBySubType(AccountSubTypeEnum::DUE_TO_EMPLOYEES);
        $cashId = $this->accountIdBySubType(AccountSubTypeEnum::CASH_CHECKING);

        $drDue = $reimbursementJe->lines->firstWhere('account_id', $dueToEmployeesId);
        $this->assertNotNull($drDue, 'DR Due to Employees — liability clearing.');
        $this->assertEquals(500.0, (float) $drDue->debit_native);

        $crCash = $reimbursementJe->lines->firstWhere('account_id', $cashId);
        $this->assertNotNull($crCash, 'CR Cash — money leaves Mercury.');
        $this->assertEquals(500.0, (float) $crCash->credit_native);

        // Across BOTH JEs (approval + reimbursement), Due to Employees should net to zero per user.
        $approvalJe = JournalEntry::query()
            ->where('source_type', 'expense')
            ->where('source_id', $reimbursed->id)
            ->first();
        $approvalJe->load('lines');

        $netDueToEmployees = 0.0;
        foreach ([$approvalJe, $reimbursementJe] as $entry) {
            foreach ($entry->lines as $line) {
                if ($line->account_id === $dueToEmployeesId) {
                    $netDueToEmployees += (float) $line->debit_base - (float) $line->credit_base;
                }
            }
        }
        $this->assertEquals(0.0, $netDueToEmployees, 'Due to Employees should net to zero after reimbursement.');
    }

    private function createAndSubmit(
        ExpensePaidByEnum $paidBy,
        ?int $paidByUsersId,
        float $amount,
        AccountSubTypeEnum $expenseAccountSubType = AccountSubTypeEnum::TRAVEL_AND_MEALS,
    ): \Kanvas\Scribe\Expenses\Models\Expense {
        $expenseAccountId = $this->accountIdBySubType($expenseAccountSubType);

        $draft = new CreateExpenseAction(
            data: new ExpenseData(
                app: $this->kanvasApp,
                company: $this->company,
                lines: new DataCollection(ExpenseLineData::class, [
                    new ExpenseLineData(
                        description: 'Test expense line',
                        amount_native: $amount,
                        expense_account_id: $expenseAccountId,
                    ),
                ]),
                expense_date: Carbon::parse('2026-06-15'),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                paid_by: $paidBy,
                paid_by_users_id: $paidByUsersId,
            ),
            user: static::$cachedUser,
        )->execute();

        return new SubmitExpenseForApprovalAction(
            expense: $draft,
            user: static::$cachedUser,
        )->execute();
    }

    private function accountIdBySubType(AccountSubTypeEnum $subType): int
    {
        $row = Account::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('account_sub_type', $subType->value)
            ->first();
        $this->assertNotNull($row, "Expected seeded account with sub_type='{$subType->value}'.");

        return (int) $row->id;
    }
}
