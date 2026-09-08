<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Scribe\Expenses\Models\Expense;

/**
 * Resolve a Scribe Expense for a tool __invoke, scoped to the tool's app + company (from
 * HasKanvasContext), returning either the Expense OR a structured error the LLM can act on — so a
 * hallucinated id never crashes the chat and never reaches another tenant's row.
 *
 *   $result = $this->resolveExpenseOrError($expense_id);
 *   if (is_array($result)) { return $result; }
 *   $expense = $result;
 *
 * `resolveMyExpenseOrError` is the self-service variant: it additionally requires the expense to be
 * one the CALLER paid. Without that second gate an employee could cancel a colleague's expense by
 * guessing an id — tenant scoping alone does not stop a member of the same company.
 */
trait ResolvesExpenseForTool
{
    /**
     * @return Expense|array{status: string, message: string}
     */
    protected function resolveExpenseOrError(int $expenseId): Expense|array
    {
        $expense = Expense::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->where('id', $expenseId)
            ->first();

        if ($expense instanceof Expense) {
            return $expense;
        }

        return [
            'status' => 'error',
            'message' => "No expense {$expenseId} exists for this company. Do not invent an id — look the "
                . 'expense up first and retry with the id it returns.',
        ];
    }

    /**
     * @return Expense|array{status: string, message: string}
     */
    protected function resolveMyExpenseOrError(int $expenseId, int $callerUsersId): Expense|array
    {
        $result = $this->resolveExpenseOrError($expenseId);

        if (is_array($result)) {
            return $result;
        }

        if ((int) $result->paid_by_users_id !== $callerUsersId) {
            return [
                'status' => 'error',
                'message' => "Expense {$expenseId} is not yours, so you cannot act on it. Tell the person it "
                    . 'belongs to someone else and that nothing was changed.',
            ];
        }

        return $result;
    }
}
