<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Expenses\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseReimbursementStatusEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Exceptions\InvalidExpenseTransitionException;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Expenses\Services\ExpenseJournalEntryComposer;
use Kanvas\Scribe\Ledger\Actions\PostJournalEntryAction;

/**
 * Records a reimbursement for an employee-paid expense.
 *
 * Prerequisites:
 *   - expense.paid_by = EMPLOYEE_PERSONAL
 *   - expense.status = APPROVED                    (the approval JE has already posted the Due to Employees liability)
 *   - expense.reimbursement_status in {APPROVED}   (PENDING means approval flow not complete; PAID means already done)
 *
 * Side effects:
 *   1. Validate preconditions
 *   2. Post the reimbursement JE (DR Due to Employees / CR Cash) via composer + PostJournalEntryAction
 *   3. Flip expense.reimbursement_status = PAID; stamp reimbursed_at + reimbursement_payment_id
 *
 * Idempotent on already-PAID expenses (no-op).
 *
 * @see plan §11.4 — Step 3: MCTekk USA ACHs Juan $500 from Mercury (the reimbursement JE)
 */
class RecordExpenseReimbursementAction
{
    public function __construct(
        public readonly Expense $expense,
        public readonly UserInterface $user,
        public readonly ?int $reimbursementPaymentId = null,
        protected readonly ExpenseJournalEntryComposer $composer = new ExpenseJournalEntryComposer(),
    ) {
    }

    public function execute(): Expense
    {
        $expense = $this->expense;

        if ($expense->paid_by !== ExpensePaidByEnum::EMPLOYEE_PERSONAL) {
            throw new InvalidExpenseTransitionException(
                "Expense {$expense->id} has paid_by='{$expense->paid_by->value}' — only EMPLOYEE_PERSONAL "
                . 'expenses generate a reimbursement obligation.'
            );
        }

        if ($expense->status !== ExpenseStatusEnum::APPROVED) {
            throw new InvalidExpenseTransitionException(
                "Expense {$expense->id} has status='{$expense->status->value}' — must be APPROVED before "
                . 'reimbursement can be recorded.'
            );
        }

        if ($expense->reimbursement_status === ExpenseReimbursementStatusEnum::PAID) {
            return $expense;       // idempotent — already reimbursed
        }

        if ($expense->reimbursement_status !== ExpenseReimbursementStatusEnum::APPROVED) {
            throw new InvalidExpenseTransitionException(
                "Expense {$expense->id} has reimbursement_status='{$expense->reimbursement_status->value}' — "
                . 'must be APPROVED before reimbursement can be recorded (run ApproveExpenseAction first).'
            );
        }

        return DB::connection('accounting')->transaction(function () use ($expense): Expense {
            $expense->reimbursed_at = Carbon::now();
            $expense->reimbursement_payment_id = $this->reimbursementPaymentId;
            $expense->reimbursement_status = ExpenseReimbursementStatusEnum::PAID;
            $expense->save();
            $expense->refresh();

            $jeData = $this->composer->composeReimbursement($expense);
            new PostJournalEntryAction(
                data: $jeData,
                postedByUser: $this->user,
            )->execute();

            $expense->emitLedgerEvent(
                eventType: 'scribe.expense.reimbursed',
                payload: [
                    'expense_number' => $expense->expense_number,
                    'paid_by_users_id' => $expense->paid_by_users_id,
                    'reimbursement_payment_id' => $this->reimbursementPaymentId,
                    'total_native' => (float) $expense->total_native,
                    'total_base' => (float) $expense->total_base,
                ],
            );

            return $expense->refresh();
        });
    }
}
