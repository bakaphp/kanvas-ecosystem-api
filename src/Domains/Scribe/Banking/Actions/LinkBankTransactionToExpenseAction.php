<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Banking\Enums\BankTransactionMatchedByEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionMatchedToTypeEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionMatchStatusEnum;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\Scribe\Expenses\Models\Expense;

/**
 * Ties a statement row to the Expense that explains it. One write, because both sides of the race
 * reach it and they must leave the row in the same shape.
 *
 * Which side got there first is the whole difference:
 *   - Approved expense already on the books when the row lands → the feed posts nothing and adopts the
 *     expense's own JE, because that entry IS this movement.
 *   - Row landed first and parked in Suspense → approval drains it, and the row keeps the JE it already
 *     posted. Overwriting that pointer would orphan the entry actually holding the Suspense balance.
 */
class LinkBankTransactionToExpenseAction
{
    public function __construct(
        public readonly BankTransaction $bankTransaction,
        public readonly Expense $expense,
        public readonly ?int $journalEntryId = null,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): BankTransaction
    {
        $this->bankTransaction->match_status = BankTransactionMatchStatusEnum::AUTO_MATCHED;
        $this->bankTransaction->matched_to_type = BankTransactionMatchedToTypeEnum::EXPENSE;
        $this->bankTransaction->matched_to_id = $this->expense->getId();
        $this->bankTransaction->matched_at = Carbon::now();
        $this->bankTransaction->matched_by = BankTransactionMatchedByEnum::SYSTEM;

        if ($this->journalEntryId !== null) {
            $this->bankTransaction->journal_entry_id = $this->journalEntryId;
        }

        $this->bankTransaction->save();

        $this->bankTransaction->emitLedgerEvent('accounting.bank_transaction.matched', payload: [
            'matched_to_type' => BankTransactionMatchedToTypeEnum::EXPENSE->value,
            'expense_id' => $this->expense->getId(),
            'expense_number' => $this->expense->expense_number,
            'amount_native' => $this->bankTransaction->amount_native,
            'already_booked' => $this->journalEntryId !== null,
        ]);

        return $this->bankTransaction;
    }
}
