<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Expenses\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Exceptions\InvalidExpenseTransitionException;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Expenses\Models\ExpenseLine;
use Kanvas\Scribe\Ledger\Models\Account;

/**
 * Books every line of a DRAFT expense against a different expense account.
 *
 * Its own action rather than a call into UpdateExpenseAction: that one replaces lines wholesale from
 * a full ExpenseData, so a caller who only wants to move the account would have to reconstruct the
 * entire DTO — including totals it must not accidentally change. Nothing here touches an amount.
 *
 * DRAFT-gated for the same reason UpdateExpenseAction is: once approved, the account is part of a
 * posted journal entry and changing it is a reclass entry, not a field edit.
 */
class RecategorizeExpenseAction
{
    public function __construct(
        public readonly Expense $expense,
        public readonly Account $account,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): Expense
    {
        if ($this->expense->status !== ExpenseStatusEnum::DRAFT) {
            throw new InvalidExpenseTransitionException(
                "Expense {$this->expense->id} cannot be recategorized — status is "
                . "'{$this->expense->status->value}'. Only draft expenses are editable; once approved the "
                . 'account is part of a posted journal entry and Finance has to post a reclass.'
            );
        }

        return DB::connection('accounting')->transaction(function (): Expense {
            $expense = $this->expense;

            ExpenseLine::query()
                ->where('expense_id', $expense->getId())
                ->update(['expense_account_id' => $this->account->getId()]);

            return $expense->refresh();
        });
    }
}
