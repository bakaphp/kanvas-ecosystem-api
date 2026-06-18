<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Expenses\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Approvals\Enums\ApprovalQueueStatusEnum;
use Kanvas\Scribe\Approvals\Models\ApprovalQueueItem;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Expenses\Services\ExpenseStateMachineService;

/**
 * Rejects a pending-approval expense. No JE posts — the expense never hit the books.
 * Also closes the matching ApprovalQueueItem as REJECTED.
 */
class RejectExpenseAction
{
    public function __construct(
        public readonly Expense $expense,
        public readonly UserInterface $rejector,
        public readonly ?string $reason = null,
        protected readonly ExpenseStateMachineService $stateMachine = new ExpenseStateMachineService(),
    ) {
    }

    public function execute(): Expense
    {
        $this->stateMachine->assertTransition($this->expense, ExpenseStatusEnum::REJECTED);

        if ($this->expense->status === ExpenseStatusEnum::REJECTED) {
            return $this->expense;
        }

        return DB::connection('accounting')->transaction(function (): Expense {
            $expense = $this->expense;
            $expense->status = ExpenseStatusEnum::REJECTED;
            $expense->rejected_at = Carbon::now();
            $expense->rejected_by_users_id = $this->rejector->getId();
            $expense->reject_reason = $this->reason;
            $expense->save();

            ApprovalQueueItem::query()
                ->where('apps_id', $expense->apps_id)
                ->where('companies_id', $expense->companies_id)
                ->where('action_type', 'approve_expense')
                ->where('target_type', 'expense')
                ->where('target_id', $expense->id)
                ->where('status', ApprovalQueueStatusEnum::PENDING->value)
                ->update([
                    'status' => ApprovalQueueStatusEnum::REJECTED->value,
                    'approved_by_users_id' => $this->rejector->getId(),
                    'approved_at' => Carbon::now(),
                    'reason' => $this->reason,
                ]);

            $expense->emitLedgerEvent(
                eventType: 'scribe.expense.rejected',
                payload: [
                    'expense_number' => $expense->expense_number,
                    'reason' => $this->reason,
                    'total_native' => (float) $expense->total_native,
                ],
            );

            return $expense->refresh();
        });
    }
}
