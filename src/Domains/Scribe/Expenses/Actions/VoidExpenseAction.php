<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Expenses\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Expenses\Enums\ExpenseReimbursementStatusEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Exceptions\InvalidExpenseTransitionException;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Expenses\Services\ExpenseStateMachine;
use Kanvas\Scribe\Ledger\Actions\ReverseJournalEntryAction;
use Kanvas\Scribe\Ledger\Enums\JournalEntryStatusEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;

/**
 * Voids an expense by posting a mirror reversal JE via ReverseJournalEntryAction.
 *
 *   - DRAFT → VOIDED: no JE existed yet, just flip status (no reversal needed).
 *   - APPROVED → VOIDED: original approval JE exists, post a mirror reversal.
 *
 * Refuses to void an already-reimbursed expense (reimbursement_status=PAID) — that requires reversing
 * two JEs and rebuilding the Due to Employees liability; defer to a separate adjusting-entry flow.
 */
class VoidExpenseAction
{
    public function __construct(
        public readonly Expense $expense,
        public readonly string $voidReasonCode,
        public readonly ?UserInterface $user = null,
        protected readonly ExpenseStateMachine $stateMachine = new ExpenseStateMachine(),
    ) {
    }

    public function execute(): Expense
    {
        $this->stateMachine->assertTransition($this->expense, ExpenseStatusEnum::VOIDED);

        if ($this->expense->reimbursement_status === ExpenseReimbursementStatusEnum::PAID) {
            throw new InvalidExpenseTransitionException(
                "Expense {$this->expense->id} has already been reimbursed (reimbursement_status=paid). "
                . 'Voiding a reimbursed expense is not supported in Cut B — issue an adjusting entry instead.'
            );
        }

        return DB::connection('accounting')->transaction(function (): Expense {
            $expense = $this->expense;

            $original = $this->findOriginalApprovalJournalEntry($expense);
            if ($original !== null) {
                new ReverseJournalEntryAction(
                    original: $original,
                    app: $expense->app,
                    company: $expense->company,
                    memo: "Expense {$expense->expense_number} void — reverses JE {$original->je_number}",
                    user: $this->user,
                    sourceType: 'expense',
                    sourceId: $expense->id,
                )->execute();
            }

            $expense->status = ExpenseStatusEnum::VOIDED;
            $expense->voided_at = Carbon::now();
            $expense->void_reason_code = $this->voidReasonCode;

            if ($expense->reimbursement_status !== ExpenseReimbursementStatusEnum::NOT_APPLICABLE
                && $expense->reimbursement_status !== ExpenseReimbursementStatusEnum::PAID) {
                $expense->reimbursement_status = ExpenseReimbursementStatusEnum::NOT_APPLICABLE;
            }

            $expense->save();

            $expense->emitLedgerEvent(
                eventType: 'scribe.expense.voided',
                payload: [
                    'expense_number' => $expense->expense_number,
                    'void_reason_code' => $this->voidReasonCode,
                    'had_approval_je' => $original !== null,
                ],
            );

            return $expense->refresh();
        });
    }

    private function findOriginalApprovalJournalEntry(Expense $expense): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('apps_id', $expense->apps_id)
            ->where('companies_id', $expense->companies_id)
            ->where('source_type', 'expense')
            ->where('source_id', $expense->id)
            ->where('status', JournalEntryStatusEnum::POSTED->value)
            ->whereNull('is_reversal_of')
            ->orderBy('id')
            ->first();
    }
}
