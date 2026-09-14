<?php

declare(strict_types=1);

namespace Tests\Scribe\E2E;

use Illuminate\Support\Carbon;
use Kanvas\Approvals\Actions\ApproveAction;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\RecordCompanyCardExpenseTool;
use Kanvas\Scribe\Banking\Actions\CreateBankAccountAction;
use Kanvas\Scribe\Banking\Actions\CreateBankTransactionAction;
use Kanvas\Scribe\Banking\Actions\MatchBankTransactionAction;
use Kanvas\Scribe\Banking\DataTransferObject\BankAccount as BankAccountData;
use Kanvas\Scribe\Banking\DataTransferObject\BankTransaction as BankTransactionData;
use Kanvas\Scribe\Banking\Enums\BankTransactionCategoryEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionDirectionEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionMatchedToTypeEnum;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseReimbursementStatusEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\Ledger\Models\JournalEntryLine;
use Tests\Scribe\ScribeTestCase;

/**
 * The chat path end to end, because every layer of it is covered alone and none of the seams were.
 *
 * An employee hands the agent a card receipt; the statement row lands the next day, before anyone has
 * approved; the owner approves on Thursday. Three separate mechanisms have to agree for the books to come
 * out right — the tool's paid_by, the policy's fallback approver, and the Suspense drain — and each one
 * failing looks like a different symptom: money owed to someone who is not owed, an expense nobody can
 * approve, or a card credited twice.
 */
final class CardReceiptFromChatFlowTest extends ScribeTestCase
{
    public function test_a_card_receipt_filed_in_chat_reaches_the_ledger_exactly_once(): void
    {
        $this->seedExpensePolicyWithFallback();
        $card = $this->seedCard();
        $employee = $this->seedTestEmployee('card-flow');

        // 1. The employee hands the agent the receipt.
        $filed = new RecordCompanyCardExpenseTool()
            ->withContext($this->kanvasApp, $this->company, $employee)
            ->__invoke(
                amount: 150.00,
                description: 'Team dinner at La Cassina',
                expense_date: '2026-06-15',
                merchant: 'La Cassina',
                card_last4: 'VISA ****0768',
            );

        $this->assertSame('submitted', $filed['status'], (string) ($filed['message'] ?? ''));

        $expense = Expense::getById($filed['expense_id']);
        $this->assertSame(ExpensePaidByEnum::COMPANY_CARD, $expense->paid_by);
        $this->assertSame(ExpenseReimbursementStatusEnum::NOT_APPLICABLE, $expense->reimbursement_status);

        // 2. The statement row lands before anyone has approved: the card is credited, the debit parks.
        $transaction = $this->landCardCharge($card, 150.00);
        new MatchBankTransactionAction($transaction, static::$cachedUser)->execute();

        $this->assertSame(-150.00, $this->netMovementOn(AccountSubTypeEnum::CREDIT_CARD_LIABILITY));
        $this->assertSame(150.00, $this->netMovementOn(AccountSubTypeEnum::SUSPENSE));

        // 3. A vendorless receipt resolves no organization approver, so only the fallback makes this
        //    request actionable at all — without it the flow stops here, permanently.
        $request = $expense->pendingApproval();
        $this->assertNotNull($request);
        $this->assertSame([$this->company->user->email], $request->pendingApproverEmails());

        new ApproveAction($request, $this->company->user)->execute();

        // 4. One real charge: credited once, nothing stranded, the debit on the expense account.
        $this->assertSame(ExpenseStatusEnum::APPROVED, $expense->refresh()->status);
        $this->assertSame(-150.00, $this->netMovementOn(AccountSubTypeEnum::CREDIT_CARD_LIABILITY));
        $this->assertSame(0.0, $this->netMovementOn(AccountSubTypeEnum::SUSPENSE));
        $this->assertSame(150.00, $this->netMovementOn(AccountSubTypeEnum::TRAVEL_AND_MEALS));

        $this->assertSame(
            BankTransactionMatchedToTypeEnum::EXPENSE,
            $transaction->refresh()->matched_to_type,
            'The statement row must end up explained by the expense that drained it.'
        );
    }

    private function seedExpensePolicyWithFallback(): void
    {
        $this->artisan('kanvas:approvals:seed-scribe-policies', [
            'apps_id' => $this->kanvasApp->getId(),
            'company_id' => $this->company->getId(),
        ])->assertSuccessful();

        $this->assertNotNull(
            ApprovalPolicy::query()->where('approval_type', 'approve_expense')->first(),
            'The flow starts from the command an operator actually runs, not a hand-built policy.'
        );
    }

    private function seedCard(): BankAccount
    {
        return new CreateBankAccountAction(
            data: new BankAccountData(
                app: $this->kanvasApp,
                company: $this->company,
                account_name: 'Company Credit Card',
                gl_account_id: $this->accountIdBySubType(AccountSubTypeEnum::CREDIT_CARD_LIABILITY),
                currency: 'USD',
                source: 'mercury',
                external_id: 'credit-' . uniqid('', true),
            ),
            user: static::$cachedUser,
        )->execute();
    }

    private function landCardCharge(BankAccount $card, float $amount): BankTransaction
    {
        $postedAt = Carbon::parse('2026-06-16 10:00:00');

        return new CreateBankTransactionAction(
            data: new BankTransactionData(
                app: $this->kanvasApp,
                company: $this->company,
                bankAccount: $card,
                postedAt: $postedAt,
                transactionDate: $postedAt->copy()->startOfDay(),
                direction: BankTransactionDirectionEnum::DEBIT,
                amountNative: $amount,
                currency: 'USD',
                amountBase: $amount,
                fxRateToBase: 1.0,
                category: BankTransactionCategoryEnum::UNKNOWN,
                counterpartyName: 'La Cassina',
                memo: 'Card charge',
                source: 'mercury',
                externalId: 'txn-' . uniqid('', true),
            ),
            user: static::$cachedUser,
        )->execute();
    }

    private function netMovementOn(AccountSubTypeEnum $subType): float
    {
        $lines = JournalEntryLine::query()
            ->where('account_id', $this->accountIdBySubType($subType))
            ->whereIn(
                'journal_entry_id',
                JournalEntry::query()
                    ->where('apps_id', $this->kanvasApp->getId())
                    ->where('companies_id', $this->company->getId())
                    ->select('id')
            )
            ->get();

        return round((float) $lines->sum('debit_base') - (float) $lines->sum('credit_base'), 2);
    }
}
