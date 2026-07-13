<?php

declare(strict_types=1);

namespace Tests\Scribe\Expenses;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Scribe\Banking\Actions\CreateBankAccountAction;
use Kanvas\Scribe\Banking\DataTransferObject\BankAccount as BankAccountData;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Expenses\Actions\ApproveExpenseAction;
use Kanvas\Scribe\Expenses\Actions\AttachExpenseReceiptAction;
use Kanvas\Scribe\Expenses\Actions\CreateExpenseAction;
use Kanvas\Scribe\Expenses\Actions\RejectExpenseAction;
use Kanvas\Scribe\Expenses\Actions\SubmitExpenseForApprovalAction;
use Kanvas\Scribe\Expenses\Actions\UpdateExpenseAction;
use Kanvas\Scribe\Expenses\Actions\VoidExpenseAction;
use Kanvas\Scribe\Expenses\DataTransferObject\Expense as ExpenseData;
use Kanvas\Scribe\Expenses\DataTransferObject\ExpenseLine as ExpenseLineData;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseReimbursementStatusEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Exceptions\InvalidExpenseTransitionException;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Expenses\Models\ExpenseReceipt;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Enums\AccountTypeEnum;
use Kanvas\Scribe\Ledger\Enums\FiscalPeriodStatusEnum;
use Kanvas\Scribe\Ledger\Enums\JournalEntryStatusEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\FiscalPeriod;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\Ledger\Services\ChartOfAccountsSeederService;
use RuntimeException;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

/**
 * Covers the PR 5b additions: RejectExpenseAction state surface, multi-line expense JE composition,
 * bank-account override path on the credit line, VoidExpenseAction (draft + approved cycles),
 * AttachExpenseReceiptAction (happy path + guards), UpdateExpenseAction (draft-only edits + line replace),
 * CreateBankAccountAction validation.
 */
class ExpenseLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'accounting'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        // JE posting dates default to Carbon::now(); freeze "now" inside the June 2026 fiscal period
        // so postings land in the open window regardless of the real wall-clock.
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_reject_pending_expense_flips_status_and_closes_queue_item(): void
    {
        $expense = $this->createAndSubmit(
            paidBy: ExpensePaidByEnum::EMPLOYEE_PERSONAL,
            paidByUsersId: static::$cachedUser->getId(),
            amount: 120.00,
        );

        $rejected = new RejectExpenseAction(
            expense: $expense,
            rejector: static::$cachedUser,
            reason: 'Receipt unreadable',
        )->execute();

        $this->assertSame(ExpenseStatusEnum::REJECTED, $rejected->status);
        $this->assertSame('Receipt unreadable', $rejected->reject_reason);
        $this->assertNotNull($rejected->rejected_at);

        // No JE — rejected expenses never hit the books
        $je = JournalEntry::query()
            ->where('source_type', 'expense')
            ->where('source_id', $rejected->id)
            ->first();
        $this->assertNull($je, 'Rejected expense must not have a JE.');

        // Reimbursement obligation cancelled too
        $this->assertNotSame(
            ExpenseReimbursementStatusEnum::APPROVED,
            $rejected->reimbursement_status,
            'Rejection must not flip reimbursement to APPROVED.',
        );
    }

    public function test_multi_line_expense_hits_multiple_expense_accounts(): void
    {
        $travelId = $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS);
        $suppliesId = $this->accountIdBySubType(AccountSubTypeEnum::OFFICE_SUPPLIES);

        $draft = new CreateExpenseAction(
            data: new ExpenseData(
                app: $this->kanvasApp,
                company: $this->company,
                lines: new DataCollection(ExpenseLineData::class, [
                    new ExpenseLineData(
                        description: 'Flight',
                        amount_native: 300.00,
                        expense_account_id: $travelId,
                    ),
                    new ExpenseLineData(
                        description: 'Conference notebook',
                        amount_native: 25.00,
                        expense_account_id: $suppliesId,
                    ),
                ]),
                expense_date: Carbon::parse('2026-06-15'),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                paid_by: ExpensePaidByEnum::COMPANY_CARD,
            ),
            user: static::$cachedUser,
        )->execute();

        $submitted = new SubmitExpenseForApprovalAction(
            expense: $draft,
            user: static::$cachedUser,
        )->execute();

        $approved = new ApproveExpenseAction(
            expense: $submitted,
            approver: static::$cachedUser,
        )->execute();

        $je = JournalEntry::query()
            ->where('source_type', 'expense')
            ->where('source_id', $approved->id)
            ->first();
        $je->load('lines');

        // Expect 3 lines: 2 DR expense + 1 CR credit-card liability
        $this->assertCount(3, $je->lines);

        $debits = $je->lines->where('debit_native', '>', 0);
        $this->assertCount(2, $debits, 'Multi-line expense must produce 2 DR lines.');

        $debitByAccount = $debits->keyBy('account_id');
        $this->assertEquals(300.0, (float) $debitByAccount[$travelId]->debit_native);
        $this->assertEquals(25.0, (float) $debitByAccount[$suppliesId]->debit_native);

        // Balance invariant
        $this->assertEquals(325.0, $je->lines->sum('debit_base'));
        $this->assertEquals(325.0, $je->lines->sum('credit_base'));
    }

    public function test_bank_account_override_routes_credit_to_specific_gl_cash_account(): void
    {
        // Seed a 2nd "Mercury Operating" Cash account, link a BankAccount to it
        $mercuryGl = new Account();
        $mercuryGl->apps_id = $this->kanvasApp->getId();
        $mercuryGl->companies_id = $this->company->getId();
        $mercuryGl->account_number = '10110';
        $mercuryGl->name = 'Cash — Mercury Operating';
        $mercuryGl->account_type = AccountTypeEnum::ASSET;
        $mercuryGl->account_sub_type = AccountSubTypeEnum::CASH_CHECKING;
        $mercuryGl->currency = 'USD';
        $mercuryGl->is_active = true;
        $mercuryGl->is_system = false;
        $mercuryGl->save();

        $bankAccount = new CreateBankAccountAction(
            data: new BankAccountData(
                app: $this->kanvasApp,
                company: $this->company,
                account_name: 'Mercury Operating',
                gl_account_id: (int) $mercuryGl->id,
                currency: 'USD',
                account_number_last4: '4242',
                institution_name: 'Mercury',
            ),
            user: static::$cachedUser,
        )->execute();

        $expenseAccountId = $this->accountIdBySubType(AccountSubTypeEnum::LEGAL_FEES);

        $draft = new CreateExpenseAction(
            data: new ExpenseData(
                app: $this->kanvasApp,
                company: $this->company,
                lines: new DataCollection(ExpenseLineData::class, [
                    new ExpenseLineData(
                        description: 'Outside counsel',
                        amount_native: 2000.00,
                        expense_account_id: $expenseAccountId,
                    ),
                ]),
                expense_date: Carbon::parse('2026-06-15'),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                paid_by: ExpensePaidByEnum::COMPANY_BANK_TRANSFER,
                bank_account_id: (int) $bankAccount->id,
            ),
            user: static::$cachedUser,
        )->execute();

        $submitted = new SubmitExpenseForApprovalAction(
            expense: $draft,
            user: static::$cachedUser,
        )->execute();

        $approved = new ApproveExpenseAction(
            expense: $submitted,
            approver: static::$cachedUser,
        )->execute();

        $je = JournalEntry::query()
            ->where('source_type', 'expense')
            ->where('source_id', $approved->id)
            ->first();
        $je->load('lines');

        $creditLine = $je->lines->firstWhere('credit_native', '>', 0);
        $this->assertNotNull($creditLine);
        $this->assertEquals(
            (int) $mercuryGl->id,
            (int) $creditLine->account_id,
            'Credit line must point at the bank account\'s GL cash account (Mercury Operating), '
            . 'not the default CASH_CHECKING fallback.',
        );
    }

    public function test_void_draft_expense_skips_je_just_flips_status(): void
    {
        $draft = new CreateExpenseAction(
            data: new ExpenseData(
                app: $this->kanvasApp,
                company: $this->company,
                lines: new DataCollection(ExpenseLineData::class, [
                    new ExpenseLineData(
                        description: 'Cancelled trip',
                        amount_native: 100.00,
                        expense_account_id: $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS),
                    ),
                ]),
                expense_date: Carbon::parse('2026-06-15'),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                paid_by: ExpensePaidByEnum::COMPANY_CARD,
            ),
            user: static::$cachedUser,
        )->execute();

        $voided = new VoidExpenseAction(
            expense: $draft,
            voidReasonCode: 'trip_cancelled',
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(ExpenseStatusEnum::VOIDED, $voided->status);
        $this->assertNotNull($voided->voided_at);
        $this->assertSame('trip_cancelled', $voided->void_reason_code);

        // No JE was ever posted for a draft, so none should exist
        $jeCount = JournalEntry::query()
            ->where('source_type', 'expense')
            ->where('source_id', $voided->id)
            ->count();
        $this->assertSame(0, $jeCount, 'Voiding a draft expense must not create any JE.');
    }

    public function test_void_approved_expense_posts_reversal_je_and_clears_reimbursement(): void
    {
        $expense = $this->createAndSubmit(
            paidBy: ExpensePaidByEnum::EMPLOYEE_PERSONAL,
            paidByUsersId: static::$cachedUser->getId(),
            amount: 750.00,
        );
        $approved = new ApproveExpenseAction(
            expense: $expense,
            approver: static::$cachedUser,
        )->execute();

        $this->assertSame(ExpenseReimbursementStatusEnum::APPROVED, $approved->reimbursement_status);

        $voided = new VoidExpenseAction(
            expense: $approved,
            voidReasonCode: 'duplicate_submission',
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(ExpenseStatusEnum::VOIDED, $voided->status);
        $this->assertSame(
            ExpenseReimbursementStatusEnum::NOT_APPLICABLE,
            $voided->reimbursement_status,
            'Voiding before reimbursement was paid must clear the obligation.',
        );

        // Two JEs now: original approval (marked REVERSED) + the reversal JE
        $original = JournalEntry::query()
            ->where('source_type', 'expense')
            ->where('source_id', $voided->id)
            ->whereNull('is_reversal_of')
            ->first();
        $this->assertNotNull($original);
        $this->assertSame(JournalEntryStatusEnum::REVERSED, $original->status);

        $reversal = JournalEntry::query()
            ->where('source_type', 'expense')
            ->where('source_id', $voided->id)
            ->whereNotNull('is_reversal_of')
            ->first();
        $this->assertNotNull($reversal);
        $this->assertEquals((int) $original->id, (int) $reversal->is_reversal_of);

        $original->load('lines');
        $reversal->load('lines');

        // Mirror invariant: DR/CR swapped, totals match
        $this->assertEquals($original->lines->sum('debit_base'), $reversal->lines->sum('credit_base'));
        $this->assertEquals($original->lines->sum('credit_base'), $reversal->lines->sum('debit_base'));

        // Sum across both JEs nets to zero on every account
        $netByAccount = [];
        foreach ([$original, $reversal] as $entry) {
            foreach ($entry->lines as $line) {
                $netByAccount[$line->account_id] = ($netByAccount[$line->account_id] ?? 0.0)
                    + (float) $line->debit_base
                    - (float) $line->credit_base;
            }
        }
        foreach ($netByAccount as $accountId => $net) {
            $this->assertEqualsWithDelta(0.0, $net, 0.0001, "Account {$accountId} should net to zero after void.");
        }
    }

    public function test_void_paid_reimbursement_is_rejected(): void
    {
        $expense = $this->createAndSubmit(
            paidBy: ExpensePaidByEnum::EMPLOYEE_PERSONAL,
            paidByUsersId: static::$cachedUser->getId(),
            amount: 200.00,
        );
        $approved = new ApproveExpenseAction(
            expense: $expense,
            approver: static::$cachedUser,
        )->execute();
        $approved->reimbursement_status = ExpenseReimbursementStatusEnum::PAID;
        $approved->save();
        $approved->refresh();

        $this->expectException(InvalidExpenseTransitionException::class);
        $this->expectExceptionMessageMatches('/already been reimbursed/');

        new VoidExpenseAction(
            expense: $approved,
            voidReasonCode: 'too_late',
            user: static::$cachedUser,
        )->execute();
    }

    public function test_update_draft_expense_replaces_lines_and_recomputes_totals(): void
    {
        $travelId = $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS);
        $suppliesId = $this->accountIdBySubType(AccountSubTypeEnum::OFFICE_SUPPLIES);

        $draft = new CreateExpenseAction(
            data: new ExpenseData(
                app: $this->kanvasApp,
                company: $this->company,
                lines: new DataCollection(ExpenseLineData::class, [
                    new ExpenseLineData(
                        description: 'Original',
                        amount_native: 100.00,
                        expense_account_id: $travelId,
                    ),
                ]),
                expense_date: Carbon::parse('2026-06-15'),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                paid_by: ExpensePaidByEnum::COMPANY_CARD,
            ),
            user: static::$cachedUser,
        )->execute();

        $this->assertEquals(100.0, (float) $draft->total_native);

        $updated = new UpdateExpenseAction(
            expense: $draft,
            data: new ExpenseData(
                app: $this->kanvasApp,
                company: $this->company,
                lines: new DataCollection(ExpenseLineData::class, [
                    new ExpenseLineData(
                        description: 'Replaced — hotel',
                        amount_native: 250.00,
                        expense_account_id: $travelId,
                    ),
                    new ExpenseLineData(
                        description: 'Replaced — notebook',
                        amount_native: 30.00,
                        expense_account_id: $suppliesId,
                    ),
                ]),
                expense_date: Carbon::parse('2026-06-16'),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                paid_by: ExpensePaidByEnum::COMPANY_CARD,
                notes: 'Edited after first save',
            ),
            user: static::$cachedUser,
        )->execute();

        $this->assertEquals(280.0, (float) $updated->total_native);
        $this->assertEquals(280.0, (float) $updated->total_base);
        $this->assertCount(2, $updated->lines);
        $this->assertSame('Edited after first save', $updated->notes);
        $this->assertSame('2026-06-16', $updated->expense_date->format('Y-m-d'));
    }

    public function test_update_non_draft_expense_throws(): void
    {
        $expense = $this->createAndSubmit(
            paidBy: ExpensePaidByEnum::COMPANY_CARD,
            paidByUsersId: null,
            amount: 50.0,
        );

        $this->expectException(InvalidExpenseTransitionException::class);
        $this->expectExceptionMessageMatches('/Only draft expenses are editable/');

        new UpdateExpenseAction(
            expense: $expense,
            data: new ExpenseData(
                app: $this->kanvasApp,
                company: $this->company,
                lines: new DataCollection(ExpenseLineData::class, [
                    new ExpenseLineData(
                        description: 'Sneaky edit',
                        amount_native: 99.0,
                        expense_account_id: $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS),
                    ),
                ]),
                expense_date: Carbon::parse('2026-06-15'),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                paid_by: ExpensePaidByEnum::COMPANY_CARD,
            ),
            user: static::$cachedUser,
        )->execute();
    }

    public function test_attach_receipt_creates_row_pointing_at_filesystem(): void
    {
        $draft = $this->createDraftExpense(amount: 75.0);
        $filesystem = $this->createFilesystemRow();

        $receipt = new AttachExpenseReceiptAction(
            expense: $draft,
            filesystem: $filesystem,
            user: static::$cachedUser,
            metadata: ['ocr_confidence' => 0.92],
        )->execute();

        $this->assertInstanceOf(ExpenseReceipt::class, $receipt);
        $this->assertSame((int) $draft->id, (int) $receipt->expense_id);
        $this->assertSame((int) $filesystem->id, (int) $receipt->filesystem_id);
        $this->assertEquals(static::$cachedUser->getId(), (int) $receipt->uploaded_by_users_id);
        $this->assertSame(0.92, $receipt->metadata['ocr_confidence']);
    }

    public function test_attach_receipt_rejected_on_voided_expense(): void
    {
        $draft = $this->createDraftExpense(amount: 75.0);
        $voided = new VoidExpenseAction(
            expense: $draft,
            voidReasonCode: 'cancelled',
            user: static::$cachedUser,
        )->execute();

        $filesystem = $this->createFilesystemRow();

        $this->expectException(InvalidExpenseTransitionException::class);
        $this->expectExceptionMessageMatches('/terminal/');

        new AttachExpenseReceiptAction(
            expense: $voided,
            filesystem: $filesystem,
            user: static::$cachedUser,
        )->execute();
    }

    public function test_attach_receipt_rejects_cross_app_filesystem(): void
    {
        $draft = $this->createDraftExpense(amount: 75.0);
        $foreignFilesystem = $this->createFilesystemRow(appsId: 999_999_999);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cross-app/');

        new AttachExpenseReceiptAction(
            expense: $draft,
            filesystem: $foreignFilesystem,
            user: static::$cachedUser,
        )->execute();
    }

    /**
     * A credit card IS a bank account — it just sits on the other side of the sheet. Its balance is what you
     * OWE, so it's backed by a Liability, not an Asset. Same as QBO/Xero. (Was previously rejected; the
     * Mercury feed pulls a real credit-card account and needs this.)
     */
    public function test_create_bank_account_accepts_a_liability_gl_account_for_credit_cards(): void
    {
        $liabilityAccount = Account::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('account_sub_type', AccountSubTypeEnum::CREDIT_CARD_LIABILITY->value)
            ->firstOrFail();

        $bankAccount = new CreateBankAccountAction(
            data: new BankAccountData(
                app: $this->kanvasApp,
                company: $this->company,
                account_name: 'Company Credit Card',
                gl_account_id: (int) $liabilityAccount->id,
                currency: 'USD',
            ),
            user: static::$cachedUser,
        )->execute();

        $this->assertSame((int) $liabilityAccount->id, (int) $bankAccount->gl_account_id);
    }

    public function test_create_bank_account_rejects_a_gl_account_that_is_neither_cash_nor_a_card(): void
    {
        $revenueAccount = Account::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('account_sub_type', AccountSubTypeEnum::SALES_REVENUE->value)
            ->firstOrFail();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must back an Asset .* or Liability/');

        new CreateBankAccountAction(
            data: new BankAccountData(
                app: $this->kanvasApp,
                company: $this->company,
                account_name: 'Wrong-type test',
                gl_account_id: (int) $revenueAccount->id,
                currency: 'USD',
            ),
            user: static::$cachedUser,
        )->execute();
    }

    public function test_create_bank_account_rejects_gl_account_from_different_company(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found in app/');

        new CreateBankAccountAction(
            data: new BankAccountData(
                app: $this->kanvasApp,
                company: $this->company,
                account_name: 'Cross-tenant attempt',
                gl_account_id: 999_999_999,
                currency: 'USD',
            ),
            user: static::$cachedUser,
        )->execute();
    }

    public function test_create_bank_account_happy_path(): void
    {
        $cashAccount = Account::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('account_sub_type', AccountSubTypeEnum::CASH_CHECKING->value)
            ->first();

        $bank = new CreateBankAccountAction(
            data: new BankAccountData(
                app: $this->kanvasApp,
                company: $this->company,
                account_name: 'Mercury Primary',
                gl_account_id: (int) $cashAccount->id,
                currency: 'USD',
                account_number_last4: '0001',
                institution_name: 'Mercury',
            ),
            user: static::$cachedUser,
        )->execute();

        $this->assertInstanceOf(BankAccount::class, $bank);
        $this->assertSame('Mercury Primary', $bank->account_name);
        $this->assertEquals((int) $cashAccount->id, (int) $bank->gl_account_id);
        $this->assertSame('USD', $bank->currency);
        $this->assertNotNull($bank->uuid);
        $this->assertTrue((bool) $bank->is_active);
    }

    private function createDraftExpense(float $amount): Expense
    {
        return new CreateExpenseAction(
            data: new ExpenseData(
                app: $this->kanvasApp,
                company: $this->company,
                lines: new DataCollection(ExpenseLineData::class, [
                    new ExpenseLineData(
                        description: 'Generic',
                        amount_native: $amount,
                        expense_account_id: $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS),
                    ),
                ]),
                expense_date: Carbon::parse('2026-06-15'),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                paid_by: ExpensePaidByEnum::COMPANY_CARD,
            ),
            user: static::$cachedUser,
        )->execute();
    }

    private function createAndSubmit(
        ExpensePaidByEnum $paidBy,
        ?int $paidByUsersId,
        float $amount,
        AccountSubTypeEnum $expenseAccountSubType = AccountSubTypeEnum::TRAVEL_AND_MEALS,
    ): Expense {
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

    private function createFilesystemRow(?int $appsId = null): Filesystem
    {
        $filesystem = new Filesystem();
        $filesystem->apps_id = $appsId ?? $this->kanvasApp->getId();
        $filesystem->companies_id = $this->company->getId();
        $filesystem->users_id = static::$cachedUser->getId();
        $filesystem->name = 'receipt.pdf';
        $filesystem->path = 'expenses/receipt.pdf';
        $filesystem->url = 'https://example.test/expenses/receipt.pdf';
        $filesystem->size = '12345';
        $filesystem->file_type = 'pdf';
        $filesystem->save();

        return $filesystem;
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
