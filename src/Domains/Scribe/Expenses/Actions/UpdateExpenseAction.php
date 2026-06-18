<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Expenses\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Expenses\DataTransferObject\Expense as ExpenseData;
use Kanvas\Scribe\Expenses\DataTransferObject\ExpenseLine as ExpenseLineData;
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
            [$totals, $baseTotals] = $this->computeTotals();
            $fxRate = (float) $this->data->fx_rate_to_base;

            // Header
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
            if ($this->data->vendor !== null) {
                $expense->vendor_organization_id = $this->data->vendor->getPayeeId();
            } else {
                $expense->vendor_organization_id = null;
            }

            $expense->save();

            // Lines — replace wholesale
            ExpenseLine::query()->where('expense_id', $expense->id)->delete();

            $sortOrder = 0;
            foreach ($this->data->lines as $lineData) {
                /** @var ExpenseLineData $lineData */
                $line = new ExpenseLine();
                $line->expense_id = $expense->id;
                $line->sort_order = $lineData->sort_order ?? $sortOrder++;
                $line->item_id = $lineData->item_id;
                $line->description = $lineData->description;
                $line->amount_native = $lineData->amount_native;
                $line->amount_base = $lineData->amount_native * $fxRate;
                $line->tax_amount_native = $lineData->tax_amount_native;
                $line->tax_amount_base = $lineData->tax_amount_native * $fxRate;
                $line->expense_account_id = $lineData->expense_account_id;
                $line->class_id = $lineData->class_id;
                $line->department_id = $lineData->department_id;
                $line->metadata = $lineData->metadata;
                $line->save();
            }

            $expense->load('lines');

            return $expense;
        });
    }

    /**
     * @return array{0: array{subtotal: float, tax: float, total: float}, 1: array{subtotal: float, tax: float, total: float}}
     */
    private function computeTotals(): array
    {
        $fxRate = (float) $this->data->fx_rate_to_base;

        $subtotal = 0.0;
        $tax = 0.0;

        foreach ($this->data->lines as $line) {
            /** @var ExpenseLineData $line */
            $subtotal += $line->amount_native;
            $tax += $line->tax_amount_native;
        }

        $total = $subtotal + $tax;

        $native = compact('subtotal', 'tax', 'total');
        $base = [
            'subtotal' => $subtotal * $fxRate,
            'tax' => $tax * $fxRate,
            'total' => $total * $fxRate,
        ];

        return [$native, $base];
    }
}
