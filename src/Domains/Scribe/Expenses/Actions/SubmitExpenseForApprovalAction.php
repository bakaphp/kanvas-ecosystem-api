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
 * Transitions DRAFT → PENDING_APPROVAL and creates an approval_queue row.
 *
 * Side effects:
 *   1. State-machine assert
 *   2. Stamp submitted_at, flip status
 *   3. Write an ApprovalQueueItem (action_type='approve_expense', target_type='expense', target_id=expense.id)
 *      — gives the agent + dashboard a queryable surface and serves as the durable audit row.
 */
class SubmitExpenseForApprovalAction
{
    public function __construct(
        public readonly Expense $expense,
        public readonly ?UserInterface $user = null,
        protected readonly ExpenseStateMachineService $stateMachine = new ExpenseStateMachineService(),
    ) {
    }

    public function execute(): Expense
    {
        $this->stateMachine->assertTransition($this->expense, ExpenseStatusEnum::PENDING_APPROVAL);

        if ($this->expense->status === ExpenseStatusEnum::PENDING_APPROVAL) {
            return $this->expense;
        }

        $expense = $this->submit();

        // Dual-write while the generic approvals domain is adopted: the legacy queue row stays
        // authoritative, and this opens the matching approval_requests row only where the tenant has
        // a policy configured. No policy means nothing changes.
        $expense->requestApproval(
            'approve_expense',
            payload: [
                'total_native' => (float) $expense->total_native,
                'currency' => $expense->currency,
                'paid_by' => $expense->paid_by->value,
            ],
            requestedBy: $this->user,
        );

        return $expense;
    }

    private function submit(): Expense
    {
        return DB::connection('accounting')->transaction(function (): Expense {
            $expense = $this->expense;

            $expense->status = ExpenseStatusEnum::PENDING_APPROVAL;
            $expense->submitted_at = Carbon::now();
            $expense->save();

            // Durable audit row in approval_queue
            ApprovalQueueItem::create([
                'apps_id' => $expense->apps_id,
                'companies_id' => $expense->companies_id,
                'requested_by_users_id' => $this->user?->getId(),
                'action_type' => 'approve_expense',
                'target_type' => 'expense',
                'target_id' => $expense->id,
                'payload' => [
                    'total_native' => (float) $expense->total_native,
                    'currency' => $expense->currency,
                    'paid_by' => $expense->paid_by->value,
                ],
                'status' => ApprovalQueueStatusEnum::PENDING,
            ]);

            return $expense->refresh();
        });
    }
}
