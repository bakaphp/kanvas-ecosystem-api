<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Expenses\Services;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntryData;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntryLineData;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Services\AccountResolverService;
use Spatie\LaravelData\DataCollection;

/**
 * Composes the JE shape for expenses — four `paid_by` patterns + the separate reimbursement cycle.
 *
 *   composeApproval:
 *     paid_by = COMPANY_CARD          → DR Expense / CR Credit Card Liability
 *     paid_by = COMPANY_CASH          → DR Expense / CR Cash on Hand
 *     paid_by = COMPANY_BANK_TRANSFER → DR Expense / CR Cash (specific bank_account → its GL cash account
 *                                                              OR fallback CASH_CHECKING)
 *     paid_by = EMPLOYEE_PERSONAL     → DR Expense / CR Due to Employees   (sub-ledger tagged by user)
 *
 *   composeReimbursement (only for EMPLOYEE_PERSONAL after MCTekk actually ACHs Juan):
 *     DR Due to Employees   {amount}      (sub-ledger tagged by user)
 *       CR Cash             {amount}      (from bank_account → its GL cash, or fallback CASH_CHECKING)
 *
 * @see plan §11.4 — Juan's $500 hotel worked example (the four JE shapes)
 */
class ExpenseJournalEntryComposerService
{
    public function __construct(
        protected readonly AccountResolverService $accountResolver = new AccountResolverService(),
    ) {
    }

    public function composeApproval(Expense $expense): JournalEntryData
    {
        $app = $expense->app;
        $company = $expense->company;
        $vendorBillableType = $expense->vendor_billable_type;
        $vendorBillableId = $expense->vendor_billable_id;
        $currency = $expense->currency;
        $fxRate = (float) $expense->fx_rate_to_base;

        // One DR Expense line per expense_line — composer respects per-line expense_account_id, so a single
        // expense can hit multiple expense accounts (Travel & Meals + Office Supplies, etc.).
        $lines = [];
        $sortOrder = 0;

        $expense->load('lines');
        foreach ($expense->lines as $line) {
            if ($line->expense_account_id === null) {
                throw new \RuntimeException(
                    "ExpenseLine {$line->id} has no expense_account_id — cannot post approval JE. "
                    . 'Assign an expense account before approving.'
                );
            }

            $lineTotalNative = (float) $line->amount_native + (float) $line->tax_amount_native;
            $lineTotalBase = (float) $line->amount_base + (float) $line->tax_amount_base;

            $lines[] = new JournalEntryLineData(
                account_id: $line->expense_account_id,
                debit_native: $lineTotalNative,
                credit_native: 0.0,
                debit_base: $lineTotalBase,
                credit_base: 0.0,
                currency: $currency,
                fx_rate_to_base: $fxRate,
                sort_order: $sortOrder++,
                vendor_billable_type: $vendorBillableType,
                vendor_billable_id: $vendorBillableId,
                item_id: $line->item_id,
                class_id: $line->class_id,
                department_id: $line->department_id,
                memo: $line->description,
            );
        }

        // Single CR line on the credit side — totals to the expense total. The credit account depends on
        // paid_by; the employee-paid case tags the line with the user_id so reporting can sum "Due to Juan".
        $creditAccount = $this->resolveCreditAccount($expense);
        $totalNative = (float) $expense->total_native;
        $totalBase = (float) $expense->total_base;

        $creditLine = new JournalEntryLineData(
            account_id: $creditAccount->id,
            debit_native: 0.0,
            credit_native: $totalNative,
            debit_base: 0.0,
            credit_base: $totalBase,
            currency: $currency,
            fx_rate_to_base: $fxRate,
            sort_order: $sortOrder,
            memo: $this->creditLineMemo($expense),
        );

        // For employee-paid expenses, tag the credit line with the user via vendor_billable so reports can
        // sum "Due to Employees" by user. Stored on the vendor_billable_* columns since "the company owes
        // user X" is structurally vendor-shaped.
        if ($expense->paid_by === ExpensePaidByEnum::EMPLOYEE_PERSONAL && $expense->paid_by_users_id !== null) {
            $creditLine = new JournalEntryLineData(
                account_id: $creditAccount->id,
                debit_native: 0.0,
                credit_native: $totalNative,
                debit_base: 0.0,
                credit_base: $totalBase,
                currency: $currency,
                fx_rate_to_base: $fxRate,
                sort_order: $sortOrder,
                vendor_billable_type: 'users',
                vendor_billable_id: $expense->paid_by_users_id,
                memo: "Expense {$expense->expense_number} — Due to user {$expense->paid_by_users_id}",
            );
        }

        $lines[] = $creditLine;

        return new JournalEntryData(
            app: $app,
            company: $company,
            postedAt: $expense->approved_at ?? Carbon::now(),
            sourceType: 'expense',
            lines: new DataCollection(JournalEntryLineData::class, $lines),
            sourceId: $expense->id,
            memo: "Expense {$expense->expense_number} approved",
            source: $expense->source,
            externalId: $expense->external_id,
            origin: $expense->origin,
        );
    }

    /**
     * Reimbursement JE — only valid for employee-paid expenses. Posts after MCTekk actually ACHs the user.
     *
     *   DR Due to Employees   {amount}   (clears the liability — tagged with users_id sub-ledger)
     *   CR Cash               {amount}   (cash leaves the company bank)
     *
     * Bank account selection: if the expense's bank_account_id is set, use that bank's GL cash account.
     * Otherwise fall back to the system CASH_CHECKING account.
     */
    public function composeReimbursement(Expense $expense): JournalEntryData
    {
        $app = $expense->app;
        $company = $expense->company;
        $currency = $expense->currency;
        $fxRate = (float) $expense->fx_rate_to_base;

        $dueAccount = $this->accountResolver->bySubType(
            $app,
            $company,
            AccountSubTypeEnum::DUE_TO_EMPLOYEES,
        );
        $cashAccount = $this->resolveCashAccount($expense);

        $totalNative = (float) $expense->total_native;
        $totalBase = (float) $expense->total_base;

        $lines = [
            // DR Due to Employees — sub-ledger tagged by user
            new JournalEntryLineData(
                account_id: $dueAccount->id,
                debit_native: $totalNative,
                credit_native: 0.0,
                debit_base: $totalBase,
                credit_base: 0.0,
                currency: $currency,
                fx_rate_to_base: $fxRate,
                sort_order: 0,
                vendor_billable_type: 'users',
                vendor_billable_id: $expense->paid_by_users_id,
                memo: "Reimbursement to user {$expense->paid_by_users_id} for expense {$expense->expense_number}",
            ),
            // CR Cash
            new JournalEntryLineData(
                account_id: $cashAccount->id,
                debit_native: 0.0,
                credit_native: $totalNative,
                debit_base: 0.0,
                credit_base: $totalBase,
                currency: $currency,
                fx_rate_to_base: $fxRate,
                sort_order: 1,
                memo: "Reimbursement to user {$expense->paid_by_users_id} for expense {$expense->expense_number}",
            ),
        ];

        return new JournalEntryData(
            app: $app,
            company: $company,
            postedAt: $expense->reimbursed_at ?? Carbon::now(),
            sourceType: 'expense_reimbursement',
            lines: new DataCollection(JournalEntryLineData::class, $lines),
            sourceId: $expense->id,
            memo: "Expense {$expense->expense_number} reimbursed",
            source: 'kanvas',
            origin: JournalEntryOriginEnum::KANVAS,
        );
    }

    private function resolveCreditAccount(Expense $expense): Account
    {
        // COMPANY_BANK_TRANSFER may have a specific bank_account_id; use its GL cash account.
        if ($expense->paid_by === ExpensePaidByEnum::COMPANY_BANK_TRANSFER && $expense->bank_account_id !== null) {
            $bankAccount = BankAccount::query()
                ->where('id', $expense->bank_account_id)
                ->where('apps_id', $expense->apps_id)
                ->where('companies_id', $expense->companies_id)
                ->first();

            if ($bankAccount !== null && $bankAccount->gl_account_id !== null) {
                $glAccount = Account::query()
                    ->where('id', $bankAccount->gl_account_id)
                    ->where('is_active', true)
                    ->first();

                if ($glAccount !== null) {
                    return $glAccount;
                }
            }
        }

        return $this->accountResolver->bySubType(
            $expense->app,
            $expense->company,
            $expense->paid_by->creditAccountSubType(),
        );
    }

    private function resolveCashAccount(Expense $expense): Account
    {
        // If a specific bank account is linked, use its GL cash account.
        if ($expense->bank_account_id !== null) {
            $bankAccount = BankAccount::query()
                ->where('id', $expense->bank_account_id)
                ->where('apps_id', $expense->apps_id)
                ->where('companies_id', $expense->companies_id)
                ->first();

            if ($bankAccount !== null && $bankAccount->gl_account_id !== null) {
                $glAccount = Account::query()
                    ->where('id', $bankAccount->gl_account_id)
                    ->where('is_active', true)
                    ->first();

                if ($glAccount !== null) {
                    return $glAccount;
                }
            }
        }

        return $this->accountResolver->bySubType(
            $expense->app,
            $expense->company,
            AccountSubTypeEnum::CASH_CHECKING,
        );
    }

    private function creditLineMemo(Expense $expense): string
    {
        return match ($expense->paid_by) {
            ExpensePaidByEnum::COMPANY_CARD => "Expense {$expense->expense_number} — Credit Card",
            ExpensePaidByEnum::COMPANY_CASH => "Expense {$expense->expense_number} — Cash on Hand",
            ExpensePaidByEnum::EMPLOYEE_PERSONAL => "Expense {$expense->expense_number} — Due to Employee",
            ExpensePaidByEnum::COMPANY_BANK_TRANSFER => "Expense {$expense->expense_number} — Bank Transfer",
        };
    }
}
