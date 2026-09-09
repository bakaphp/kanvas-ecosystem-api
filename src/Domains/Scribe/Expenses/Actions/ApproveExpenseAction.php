<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Expenses\Actions;

use Baka\Contracts\PayeeInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Approvals\Enums\ApprovalQueueStatusEnum;
use Kanvas\Scribe\Approvals\Models\ApprovalQueueItem;
use Kanvas\Scribe\Banking\Actions\LinkBankTransactionToExpenseAction;
use Kanvas\Scribe\Banking\Services\BankTransactionMatchService;
use Kanvas\Scribe\DocumentSequences\Enums\DocumentTypeEnum;
use Kanvas\Scribe\DocumentSequences\Services\DocumentNumberAllocatorService;
use Kanvas\Scribe\Expenses\Enums\ExpenseReimbursementStatusEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Expenses\Services\ExpenseJournalEntryComposerService;
use Kanvas\Scribe\Expenses\Services\ExpenseStateMachineService;
use Kanvas\Scribe\Ledger\Actions\PostJournalEntryAction;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Services\AccountResolverService;

/**
 * Transitions PENDING_APPROVAL → APPROVED and posts the approval JE (DR Expense / CR {paid_by-driven}).
 *
 * Atomic side effects (single accounting-DB transaction):
 *   1. State-machine assert
 *   2. Freeze vendor snapshot (if vendor present)
 *   3. Allocate expense_number via DocumentNumberAllocatorService
 *   4. Flip status + stamp approved_at / approved_by_users_id
 *   5. For employee-paid: flip reimbursement_status PENDING → APPROVED
 *   6. Compose JE via ExpenseJournalEntryComposerService::composeApproval + post via PostJournalEntryAction
 *   7. Close the matching ApprovalQueueItem (if any) — APPROVED
 *
 * Step 6 has a second shape. The bank feed does not wait for an approver: when the statement row lands
 * first it credits the card itself and parks the debit in Suspense. Crediting the card again here would
 * credit one real charge twice and strand the parked amount forever, so an approval that finds such a row
 * drains it instead — same per-line debits, CR Suspense — and links the row to this expense.
 * BankTransactionMatchService guards the opposite order; both halves are needed, because which side of
 * the race arrives first is not something either one can control.
 *
 * @see plan §11.4 — Juan's hotel; this is the "Finance approves" step that posts the DR Expense / CR Due to Employees JE
 */
class ApproveExpenseAction
{
    public function __construct(
        public readonly Expense $expense,
        public readonly UserInterface $approver,
        public readonly ?PayeeInterface $vendor = null,
        protected readonly ExpenseStateMachineService $stateMachine = new ExpenseStateMachineService(),
        protected readonly ExpenseJournalEntryComposerService $composer = new ExpenseJournalEntryComposerService(),
        protected readonly ?DocumentNumberAllocatorService $allocator = null,
        protected readonly BankTransactionMatchService $matcher = new BankTransactionMatchService(),
        protected readonly AccountResolverService $accountResolver = new AccountResolverService(),
    ) {
    }

    public function execute(): Expense
    {
        $this->stateMachine->assertTransition($this->expense, ExpenseStatusEnum::APPROVED);

        if ($this->expense->status === ExpenseStatusEnum::APPROVED) {
            return $this->expense;
        }

        return DB::connection('accounting')->transaction(function (): Expense {
            $expense = $this->expense;

            $this->freezeVendorSnapshotIfPresent($expense);
            $this->allocateExpenseNumberIfMissing($expense);

            $expense->status = ExpenseStatusEnum::APPROVED;
            $expense->approved_at = Carbon::now();
            $expense->approved_by_users_id = $this->approver->getId();

            if ($expense->reimbursement_status === ExpenseReimbursementStatusEnum::PENDING) {
                $expense->reimbursement_status = ExpenseReimbursementStatusEnum::APPROVED;
            }

            $expense->save();
            $expense->refresh();
            $expense->load('lines');

            $parkedCharge = $this->matcher->findSuspendedChargeFor($expense);
            $suspenseAccount = $parkedCharge === null
                ? null
                : $this->accountResolver->bySubType($expense->app, $expense->company, AccountSubTypeEnum::SUSPENSE);

            $jeData = $this->composer->composeApproval($expense, $suspenseAccount);

            new PostJournalEntryAction(
                data: $jeData,
                postedByUser: $this->approver,
            )->execute();

            if ($parkedCharge !== null) {
                // No journalEntryId: the row keeps the entry it already posted, which is the one still
                // holding the Suspense balance this approval just drained.
                new LinkBankTransactionToExpenseAction(
                    bankTransaction: $parkedCharge,
                    expense: $expense,
                    user: $this->approver,
                )->execute();
            }

            $this->closeApprovalQueueItem($expense);

            $expense->emitLedgerEvent(
                eventType: 'scribe.expense.approved',
                payload: [
                    'expense_number' => $expense->expense_number,
                    'paid_by' => $expense->paid_by->value,
                    'paid_by_users_id' => $expense->paid_by_users_id,
                    'currency' => $expense->currency,
                    'total_native' => (float) $expense->total_native,
                    'total_base' => (float) $expense->total_base,
                    'reimbursement_status' => $expense->reimbursement_status->value,
                ],
            );

            return $expense->refresh();
        });
    }

    private function freezeVendorSnapshotIfPresent(Expense $expense): void
    {
        if ($this->vendor === null) {
            return;
        }

        $expense->vendor_organization_id = $this->vendor->getPayeeId();
        $expense->vendor_display_name = $this->vendor->getPayeeDisplayName();
        $expense->vendor_legal_name = $this->vendor->getPayeeLegalName();
        $expense->vendor_tax_id = $this->vendor->getPayeeTaxId();
        $expense->vendor_email = $this->vendor->getPayeeEmail();
    }

    private function allocateExpenseNumberIfMissing(Expense $expense): void
    {
        if ($expense->expense_number !== null && $expense->expense_number !== '') {
            return;
        }

        $allocator = $this->allocator ?? new DocumentNumberAllocatorService();
        $expense->expense_number = $allocator->allocate(
            $expense->apps_id,
            $expense->companies_id,
            DocumentTypeEnum::EXPENSE,
            defaultPrefix: '',
        );
    }

    private function closeApprovalQueueItem(Expense $expense): void
    {
        ApprovalQueueItem::query()
            ->where('apps_id', $expense->apps_id)
            ->where('companies_id', $expense->companies_id)
            ->where('action_type', 'approve_expense')
            ->where('target_type', 'expense')
            ->where('target_id', $expense->id)
            ->where('status', ApprovalQueueStatusEnum::PENDING->value)
            ->update([
                'status' => ApprovalQueueStatusEnum::APPROVED->value,
                'approved_by_users_id' => $this->approver->getId(),
                'approved_at' => Carbon::now(),
            ]);
    }
}
