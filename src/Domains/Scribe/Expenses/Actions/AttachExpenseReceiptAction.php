<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Expenses\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Exceptions\InvalidExpenseTransitionException;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Expenses\Models\ExpenseReceipt;
use RuntimeException;

/**
 * Links one or more uploaded `Filesystem` rows to an `Expense` by writing rows into
 * `accounting.expense_receipts`. Cross-DB pointer; no DDL FK (Filesystem lives on `mysql`,
 * receipt row lives on `accounting`).
 *
 * Receipts can be attached at any non-terminal status — drafts AND post-approval. (You might
 * scan in the meal receipt the next day after the trip got approved.) But voided/rejected
 * expenses are frozen: attaching to those is rejected to avoid polluting closed records.
 */
class AttachExpenseReceiptAction
{
    public function __construct(
        public readonly Expense $expense,
        public readonly Filesystem $filesystem,
        public readonly ?UserInterface $user = null,
        public readonly ?array $metadata = null,
    ) {
    }

    public function execute(): ExpenseReceipt
    {
        $this->guardStatus();
        $this->guardScope();

        return DB::connection('accounting')->transaction(function (): ExpenseReceipt {
            $receipt = new ExpenseReceipt();
            $receipt->expense_id = $this->expense->id;
            $receipt->filesystem_id = (int) $this->filesystem->getKey();
            $receipt->uploaded_at = Carbon::now();
            $receipt->uploaded_by_users_id = $this->user?->getId();
            $receipt->metadata = $this->metadata;
            $receipt->save();

            return $receipt->refresh();
        });
    }

    private function guardStatus(): void
    {
        if (in_array($this->expense->status, [ExpenseStatusEnum::VOIDED, ExpenseStatusEnum::REJECTED], true)) {
            throw new InvalidExpenseTransitionException(
                "Cannot attach receipt to expense {$this->expense->id} — status is "
                . "'{$this->expense->status->value}' (terminal). Attach before void/reject."
            );
        }
    }

    private function guardScope(): void
    {
        // Filesystem rows are tenant-scoped via apps_id + companies_id. Refuse cross-tenant attaches.
        if ((int) $this->filesystem->apps_id !== (int) $this->expense->apps_id) {
            throw new RuntimeException(
                "Filesystem row {$this->filesystem->getKey()} belongs to app {$this->filesystem->apps_id}, "
                . "expense belongs to app {$this->expense->apps_id}. Cross-app attach rejected."
            );
        }

        if ((int) $this->filesystem->companies_id !== (int) $this->expense->companies_id) {
            throw new RuntimeException(
                "Filesystem row {$this->filesystem->getKey()} belongs to company {$this->filesystem->companies_id}, "
                . "expense belongs to company {$this->expense->companies_id}. Cross-company attach rejected."
            );
        }
    }
}
