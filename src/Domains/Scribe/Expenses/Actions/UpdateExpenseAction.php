<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Expenses\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Expenses\Actions\Concerns\PersistsExpenseLines;
use Kanvas\Scribe\Expenses\DataTransferObject\Expense as ExpenseData;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseReimbursementStatusEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Exceptions\InvalidExpenseTransitionException;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Expenses\Models\ExpenseLine;

/**
 * Updates a DRAFT expense (header + lines + vendor + payment context).
 *
 * Hard-gated to status=DRAFT — once an expense has been submitted/approved, header mutations are not
 * allowed (an approved expense has a posted JE; mutating it would silently change the books).
 * For post-approval changes the only legal path is Void + Re-create.
 *
 * Lines are replaced wholesale (delete + re-insert) rather than diffed — drafts don't have JE history
 * tying line ids to anything, so churning ids is harmless and the implementation stays simple.
 */
class UpdateExpenseAction
{
    use PersistsExpenseLines;

    public function __construct(
        public readonly Expense $expense,
        public readonly ExpenseData $data,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): Expense
    {
        if ($this->expense->status !== ExpenseStatusEnum::DRAFT) {
            throw new InvalidExpenseTransitionException(
                "Expense {$this->expense->id} cannot be updated — status is '{$this->expense->status->value}'. "
                . 'Only draft expenses are editable. Void + recreate is the path for post-approval changes.'
            );
        }

        return DB::connection('accounting')->transaction(function (): Expense {
            $expense = $this->expense;
            [$totals, $baseTotals] = $this->computeTotals($this->data);

            $expense->expense_date = $this->data->expense_date;
            $expense->paid_by = $this->data->paid_by;
            $expense->paid_by_users_id = $this->data->paid_by_users_id;
            $expense->payment_method_id = $this->data->payment_method_id;
            $expense->bank_account_id = $this->data->bank_account_id;
            $expense->reimbursement_status = $this->data->paid_by === ExpensePaidByEnum::EMPLOYEE_PERSONAL
                ? ExpenseReimbursementStatusEnum::PENDING
                : ExpenseReimbursementStatusEnum::NOT_APPLICABLE;
            $expense->currency = $this->data->currency;
            $expense->fx_rate_to_base = $this->data->fx_rate_to_base;
            $expense->fx_rate_at = $this->data->expense_date;
            $expense->subtotal_native = $totals['subtotal'];
            $expense->tax_native = $totals['tax'];
            $expense->total_native = $totals['total'];
            $expense->subtotal_base = $baseTotals['subtotal'];
            $expense->tax_base = $baseTotals['tax'];
            $expense->total_base = $baseTotals['total'];
            $expense->tax_metadata = $this->data->tax_metadata;
            $expense->regional_compliance = $this->data->regional_compliance;
            $expense->notes = $this->data->notes;
            $expense->internal_notes = $this->data->internal_notes;
            $expense->metadata = $this->data->metadata;

            // Vendor reference — allow swap (or clear) while in draft
            $expense->vendor_organization_id = $this->data->vendor?->getPayeeId();

            $expense->save();

            ExpenseLine::query()->where('expense_id', $expense->id)->delete();

            $this->persistLines($expense, $this->data);

            $expense->load('lines');

            return $expense;
        });
    }
}
