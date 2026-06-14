<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Expenses\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Expenses\Enums\ExpenseReimbursementStatusEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Exceptions\InvalidExpenseTransitionException;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Expenses\Services\ExpenseStateMachine;
use Kanvas\Scribe\Ledger\Actions\PostJournalEntryAction;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntryData;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntryLineData;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Kanvas\Scribe\Ledger\Enums\JournalEntryStatusEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Spatie\LaravelData\DataCollection;

/**
 * Voids an expense by posting a mirror (DR↔CR swap) JE. Two valid entry points:
 *
 *   - DRAFT → VOIDED: no JE existed yet, so no reversal needed; just flip status.
 *   - APPROVED → VOIDED: original approval JE exists; post a mirror JE that reverses it; mark original as 'reversed'.
 *     If a reimbursement JE was already posted, that ALSO needs reversing (separate concern; for safety we
 *     refuse to void an already-reimbursed expense — that requires a more careful flow that PR 5 defers).
 *
 * Parallel to VoidInvoiceAction / VoidSalesReceiptAction — same mirror-JE pattern; reuses helpers inline.
 */
class VoidExpenseAction
{
    public function __construct(
        public readonly Expense $expense,
        public readonly string $voidReasonCode,
        public readonly ?UserInterface $user = null,
        protected readonly ExpenseStateMachine $stateMachine = new ExpenseStateMachine(),
    ) {
    }

    public function execute(): Expense
    {
        $this->stateMachine->assertTransition($this->expense, ExpenseStatusEnum::VOIDED);

        // Refuse to void an expense whose reimbursement has already been PAID — that requires reversing
        // two JEs and rebuilding the Due to Employees liability. Defer that flow to a follow-up.
        if ($this->expense->reimbursement_status === ExpenseReimbursementStatusEnum::PAID) {
            throw new InvalidExpenseTransitionException(
                "Expense {$this->expense->id} has already been reimbursed (reimbursement_status=paid). "
                . 'Voiding a reimbursed expense is not supported in Cut B — issue an adjusting entry instead.'
            );
        }

        return DB::connection('accounting')->transaction(function (): Expense {
            $expense = $this->expense;

            // Find the original approval JE (if it exists — drafts don't have one).
            $original = $this->findOriginalApprovalJournalEntry($expense);

            if ($original !== null) {
                $this->postReversalJe($expense, $original);
                $original->status = JournalEntryStatusEnum::REVERSED;
                $original->save();
            }

            $expense->status = ExpenseStatusEnum::VOIDED;
            $expense->voided_at = Carbon::now();
            $expense->void_reason_code = $this->voidReasonCode;

            // If the expense had a pending reimbursement obligation, void clears it — no money owed anymore.
            if ($expense->reimbursement_status !== ExpenseReimbursementStatusEnum::NOT_APPLICABLE
                && $expense->reimbursement_status !== ExpenseReimbursementStatusEnum::PAID) {
                $expense->reimbursement_status = ExpenseReimbursementStatusEnum::NOT_APPLICABLE;
            }

            $expense->save();

            return $expense->refresh();
        });
    }

    private function findOriginalApprovalJournalEntry(Expense $expense): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('apps_id', $expense->apps_id)
            ->where('companies_id', $expense->companies_id)
            ->where('source_type', 'expense')
            ->where('source_id', $expense->id)
            ->where('status', JournalEntryStatusEnum::POSTED->value)
            ->whereNull('is_reversal_of')
            ->orderBy('id')
            ->first();
    }

    private function postReversalJe(Expense $expense, JournalEntry $original): void
    {
        $original->load('lines');

        $mirrored = [];
        foreach ($original->lines as $i => $line) {
            $mirrored[] = new JournalEntryLineData(
                account_id: $line->account_id,
                debit_native: (float) $line->credit_native,
                credit_native: (float) $line->debit_native,
                debit_base: (float) $line->credit_base,
                credit_base: (float) $line->debit_base,
                currency: $line->currency,
                fx_rate_to_base: (float) $line->fx_rate_to_base,
                sort_order: $i,
                customer_billable_type: $line->customer_billable_type,
                customer_billable_id: $line->customer_billable_id,
                vendor_billable_type: $line->vendor_billable_type,
                vendor_billable_id: $line->vendor_billable_id,
                item_id: $line->item_id,
                class_id: $line->class_id,
                department_id: $line->department_id,
                memo: $line->memo ? "REVERSAL — {$line->memo}" : null,
                metadata: $line->metadata,
            );
        }

        new PostJournalEntryAction(
            data: new JournalEntryData(
                app: $expense->app,
                company: $expense->company,
                postedAt: Carbon::now(),
                sourceType: 'expense',
                lines: new DataCollection(JournalEntryLineData::class, $mirrored),
                sourceId: $expense->id,
                memo: "Expense {$expense->expense_number} void — reverses JE {$original->je_number}",
                isAdjustment: true,
                isReversalOf: $original->id,
                source: 'kanvas',
                origin: JournalEntryOriginEnum::KANVAS,
            ),
            postedByUser: $this->user,
        )->execute();
    }
}
