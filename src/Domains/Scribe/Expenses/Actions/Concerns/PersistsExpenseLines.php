<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Expenses\Actions\Concerns;

use Baka\Support\Money;
use Kanvas\Scribe\Expenses\DataTransferObject\Expense as ExpenseData;
use Kanvas\Scribe\Expenses\DataTransferObject\ExpenseLine as ExpenseLineData;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Expenses\Models\ExpenseLine;
use Kanvas\Scribe\Support\DocumentTotals;

trait PersistsExpenseLines
{
    protected function persistLines(Expense $expense, ExpenseData $data): void
    {
        $fxRate = (float) $data->fx_rate_to_base;
        $sortOrder = 0;

        foreach ($data->lines as $lineData) {
            /** @var ExpenseLineData $lineData */
            $line = new ExpenseLine();
            $line->expense_id = $expense->id;
            $line->sort_order = $lineData->sort_order ?? $sortOrder++;
            $line->item_id = $lineData->item_id;
            $line->description = $lineData->description;
            $line->amount_native = $lineData->amount_native;
            $line->amount_base = Money::toBase($lineData->amount_native, $fxRate);
            $line->tax_amount_native = $lineData->tax_amount_native;
            $line->tax_amount_base = Money::toBase($lineData->tax_amount_native, $fxRate);
            $line->expense_account_id = $lineData->expense_account_id;
            $line->class_id = $lineData->class_id;
            $line->department_id = $lineData->department_id;
            $line->metadata = $lineData->metadata;
            $line->save();
        }
    }

    /**
     * @return array{0: array{subtotal: float, tax: float, total: float}, 1: array{subtotal: float, tax: float, total: float}}
     */
    protected function computeTotals(ExpenseData $data): array
    {
        return DocumentTotals::compute(
            array_map(
                static fn (ExpenseLineData $line): array => [
                    'subtotal' => $line->amount_native,
                    'tax' => $line->tax_amount_native,
                ],
                $data->lines->all(),
            ),
            $data->fx_rate_to_base,
        );
    }
}
