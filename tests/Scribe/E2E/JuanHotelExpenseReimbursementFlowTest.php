<?php

declare(strict_types=1);

namespace Tests\Scribe\E2E;

use Illuminate\Support\Carbon;
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
use Kanvas\Scribe\Ledger\Enums\JournalEntryStatusEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\Reports\Repositories\AccountActivityRepository;
use Kanvas\Scribe\Reports\Repositories\BalanceSheetRepository;
use Kanvas\Scribe\Reports\Repositories\ProfitAndLossRepository;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

/**
 * Flow 2 — the canonical Expense reimbursement cycle (plan doc §10 "Juan's hotel").
 *
 * Juan (an employee) personally pays $500 for a hotel during a business trip and submits the expense
 * for approval. End-to-end:
 *   1. Create draft Expense (paid_by=EMPLOYEE_PERSONAL, Travel & Meals line)
 *   2. SubmitExpenseForApprovalAction → PENDING_APPROVAL
 *   3. ApproveExpenseAction → APPROVED + posts approval JE (DR Travel & Meals / CR Due to Employees)
 *      reimbursement_status → PENDING
 *   4. RecordExpenseReimbursementAction → reimbursement_status=PAID + posts reimbursement JE
 *      (DR Due to Employees / CR Cash)
 *
 * What it proves:
 *   - Both POSTED JEs land balanced (DR == CR per JE)
 *   - GL post-reimbursement: Travel & Meals=+500 (expense incurred), Due to Employees=0 (cleared),
 *     Cash=-500 (paid out)
 *   - P&L picks up the operating expense
 *   - Both ledger events emitted (scribe.expense.approved + scribe.expense.reimbursed)
 *
 * Important distinction from a company-card expense:
 *   - paid_by=EMPLOYEE_PERSONAL → 2 JEs (approval + reimbursement) via the Due to Employees liability
 *   - paid_by=COMPANY_CARD → 1 JE (direct DR Expense / CR Credit Card Liability), no reimbursement leg
 */
class JuanHotelExpenseReimbursementFlowTest extends ScribeTestCase
{
    public function test_full_employee_personal_reimbursement_cycle(): void
    {
        $juanUserId = static::$cachedUser->getId();

        // ── Step 1 — create draft expense ──
        $travelAccountId = $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS);
        $draft = new CreateExpenseAction(
            data: new ExpenseData(
                app: $this->kanvasApp,
                company: $this->company,
                lines: new DataCollection(ExpenseLineData::class, [
                    new ExpenseLineData(
                        description: 'Hotel — 2 nights for client meeting',
                        amount_native: 500.0,
                        expense_account_id: $travelAccountId,
                    ),
                ]),
                expense_date: Carbon::parse('2026-06-10'),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                paid_by: ExpensePaidByEnum::EMPLOYEE_PERSONAL,
                paid_by_users_id: $juanUserId,
                notes: 'Reimbursement requested',
            ),
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(ExpenseStatusEnum::DRAFT, $draft->status);
        $this->assertEqualsWithDelta(500.0, (float) $draft->total_native, 0.005);
        $this->assertSame($juanUserId, (int) $draft->paid_by_users_id);

        // ── Step 2 — submit for approval ──
        $submitted = new SubmitExpenseForApprovalAction(
            expense: $draft,
            user: static::$cachedUser,
        )->execute();
        $this->assertSame(ExpenseStatusEnum::PENDING_APPROVAL, $submitted->status);

        // ── Step 3 — approve → JE posted, reimbursement obligation opens ──
        $approved = new ApproveExpenseAction(
            expense: $submitted,
            approver: static::$cachedUser,
        )->execute();

        $this->assertSame(ExpenseStatusEnum::APPROVED, $approved->status);
        $this->assertSame(
            ExpenseReimbursementStatusEnum::APPROVED,
            $approved->reimbursement_status,
            'EMPLOYEE_PERSONAL approved expense opens a reimbursement liability (status=APPROVED, waiting for payout).',
        );

        $approvalJes = $this->postedJesFor('expense', (int) $approved->id);
        $this->assertCount(1, $approvalJes);
        $this->assertJeBalanced($approvalJes->first());

        // ── Step 4 — reimburse → second JE clears the liability ──
        $reimbursed = new RecordExpenseReimbursementAction(
            expense: $approved,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(
            ExpenseReimbursementStatusEnum::PAID,
            $reimbursed->reimbursement_status,
        );

        // Approval JE uses source_type='expense'; reimbursement JE uses 'expense_reimbursement'
        // (so they're independently queryable but both link back via source_id).
        $approvalJesPost = $this->postedJesFor('expense', (int) $reimbursed->id);
        $reimbursementJes = $this->postedJesFor('expense_reimbursement', (int) $reimbursed->id);
        $this->assertCount(1, $approvalJesPost, 'Approval JE on source_type=expense.');
        $this->assertCount(1, $reimbursementJes, 'Reimbursement JE on source_type=expense_reimbursement.');
        foreach ($approvalJesPost->merge($reimbursementJes) as $je) {
            $this->assertJeBalanced($je);
        }

        // ── Cross-cutting: GL invariants ──
        $accountIds = $this->accountIdsByKey([
            AccountSubTypeEnum::TRAVEL_AND_MEALS,
            AccountSubTypeEnum::DUE_TO_EMPLOYEES,
            AccountSubTypeEnum::CASH_CHECKING,
        ]);
        $balances = new AccountActivityRepository()->getBalancesAsOf(
            app: $this->kanvasApp,
            company: $this->company,
            accountIds: array_values($accountIds),
            asOfDate: Carbon::parse('2026-06-30'),
        );

        $this->assertEqualsWithDelta(
            500.0,
            $balances[$accountIds[AccountSubTypeEnum::TRAVEL_AND_MEALS->value]],
            0.005,
            'Expense was incurred — Travel & Meals is DR-normal, +500.',
        );
        $this->assertEqualsWithDelta(
            0.0,
            $balances[$accountIds[AccountSubTypeEnum::DUE_TO_EMPLOYEES->value]],
            0.005,
            'Due to Employees cleared once the reimbursement JE posts.',
        );
        $this->assertEqualsWithDelta(
            -500.0,
            $balances[$accountIds[AccountSubTypeEnum::CASH_CHECKING->value]],
            0.005,
            'Cash paid out — Cash is DR-normal, balance shows the disbursement as negative.',
        );

        // ── Cross-cutting: P&L picks up the expense ──
        $pnl = new ProfitAndLossRepository()->generate(
            app: $this->kanvasApp,
            company: $this->company,
            periodStart: Carbon::parse('2026-06-01'),
            periodEnd: Carbon::parse('2026-06-30'),
        );
        $this->assertEqualsWithDelta(500.0, $pnl->operating_expenses->total, 0.005);
        $this->assertEqualsWithDelta(-500.0, $pnl->net_income, 0.005, 'Expense without revenue → net loss.');

        // ── Cross-cutting: balance sheet still balances ──
        $bs = new BalanceSheetRepository()->generate(
            app: $this->kanvasApp,
            company: $this->company,
            asOf: Carbon::parse('2026-06-30'),
        );
        $this->assertTrue($bs->is_balanced, 'BS must balance after reimbursement cycle: A = L + E.');
    }

    public function test_company_card_expense_skips_reimbursement_leg(): void
    {
        // Same expense paid by company card should approve in one JE (no Due to Employees).
        $expense = $this->approveTestExpense(
            amount: 200.0,
            paidBy: ExpensePaidByEnum::COMPANY_CARD,
        );

        $this->assertSame(ExpenseStatusEnum::APPROVED, $expense->status);
        $this->assertSame(
            ExpenseReimbursementStatusEnum::NOT_APPLICABLE,
            $expense->reimbursement_status,
            'COMPANY_CARD expenses bypass the reimbursement liability entirely.',
        );

        $jes = $this->postedJesFor('expense', (int) $expense->id);
        $this->assertCount(1, $jes, 'Only the approval JE; no reimbursement leg.');

        // Due to Employees must remain at zero — the COMPANY_CARD path uses the credit-card liability.
        $dueToEmpId = $this->accountIdBySubType(AccountSubTypeEnum::DUE_TO_EMPLOYEES);
        $balances = new AccountActivityRepository()->getBalancesAsOf(
            app: $this->kanvasApp,
            company: $this->company,
            accountIds: [$dueToEmpId],
            asOfDate: Carbon::parse('2026-06-30'),
        );
        $this->assertEqualsWithDelta(0.0, $balances[$dueToEmpId], 0.005);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, JournalEntry>
     */
    private function postedJesFor(string $sourceType, int $sourceId)
    {
        return JournalEntry::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', JournalEntryStatusEnum::POSTED->value)
            ->whereNull('is_reversal_of')
            ->orderBy('id')
            ->get();
    }

    private function assertJeBalanced(JournalEntry $je): void
    {
        $je->load('lines');
        $debit = (float) $je->lines->sum('debit_base');
        $credit = (float) $je->lines->sum('credit_base');
        $this->assertEqualsWithDelta(
            $debit,
            $credit,
            0.005,
            "JE {$je->id} ({$je->source_type}) must be balanced — DR={$debit}, CR={$credit}.",
        );
    }

    /**
     * @param  list<AccountSubTypeEnum>  $subTypes
     * @return array<string, int>  keyed by sub-type value
     */
    private function accountIdsByKey(array $subTypes): array
    {
        $out = [];
        foreach ($subTypes as $sub) {
            $out[$sub->value] = $this->accountIdBySubType($sub);
        }

        return $out;
    }
}
