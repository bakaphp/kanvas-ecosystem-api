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
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Expenses\Models\ExpenseLine;

/**
 * Creates a DRAFT expense.
 *
 * No JE post — drafts haven't hit the books yet. JE posts at ApproveExpenseAction.
 * Reimbursement status set to PENDING if paid_by=employee_personal, NOT_APPLICABLE otherwise.
 */
class CreateExpenseAction
{
    public function __construct(
        public readonly ExpenseData $data,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): Expense
    {
        return DB::connection('accounting')->transaction(function (): Expense {
            [$totals, $baseTotals] = $this->computeTotals();
            $fxRate = (float) $this->data->fx_rate_to_base;

            $expense = new Expense();
            $expense->apps_id = $this->data->app->getId();
            $expense->companies_id = $this->data->company->getId();
            $expense->status = ExpenseStatusEnum::DRAFT;
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
            $expense->source = $this->data->source;
            $expense->external_id = $this->data->external_id;
            $expense->external_url = $this->data->external_url;
            $expense->origin = $this->data->origin;
            $expense->metadata = $this->data->metadata;
            $expense->users_id = $this->user?->getId();
            $expense->expense_number = $this->data->expense_number;

            // Vendor reference (snapshot fields stay null until Approval; same pattern as Invoice billable)
            if ($this->data->vendor !== null) {
                $expense->vendor_organization_id = $this->data->vendor->getPayeeId();
            }

            $expense->save();

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
