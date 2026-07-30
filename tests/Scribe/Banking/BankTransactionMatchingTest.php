<?php

declare(strict_types=1);

namespace Tests\Scribe\Banking;

use Illuminate\Support\Carbon;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Banking\Actions\CreateBankAccountAction;
use Kanvas\Scribe\Banking\Actions\CreateBankTransactionAction;
use Kanvas\Scribe\Banking\Actions\MatchBankTransactionAction;
use Kanvas\Scribe\Banking\Actions\ReclassifySuspenseAction;
use Kanvas\Scribe\Banking\Actions\SettleBillFromSuspenseAction;
use Kanvas\Scribe\Banking\DataTransferObject\BankAccount as BankAccountData;
use Kanvas\Scribe\Banking\DataTransferObject\BankTransaction as BankTransactionData;
use Kanvas\Scribe\Banking\Enums\BankTransactionCategoryEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionDirectionEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionMatchedToTypeEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionMatchOutcomeEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionMatchStatusEnum;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\Actions\ReceiveBillAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Expenses\Actions\CreateExpenseAction;
use Kanvas\Scribe\Expenses\DataTransferObject\Expense as ExpenseData;
use Kanvas\Scribe\Expenses\DataTransferObject\ExpenseLine as ExpenseLineData;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\Ledger\Models\JournalEntryLine;
use Kanvas\Scribe\Payments\Models\Payment;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

/**
 * PR 3 — the auto-matcher. This is where "the bill shows as paid" actually happens.
 *
 * The two assertions this file exists for:
 *   1. A matched transaction posts NO bank JE of its own — the sub-ledger payment already booked the cash.
 *      A second entry would double-count it, and no test elsewhere would catch that.
 *   2. The Suspense → approve → settle loop nets Cash to −X exactly ONCE, not twice.
 */
final class BankTransactionMatchingTest extends ScribeTestCase
{
    private BankAccount $bankAccount;

    protected function afterScribeSetUp(): void
    {
        $this->bankAccount = new CreateBankAccountAction(
            data: new BankAccountData(
                app: $this->kanvasApp,
                company: $this->company,
                account_name: 'Mercury Checking',
                gl_account_id: $this->accountIdBySubType(AccountSubTypeEnum::CASH_CHECKING),
                currency: 'USD',
                institution_name: 'Mercury',
                source: 'mercury',
                external_id: 'acct-' . uniqid('', true),
            ),
            user: static::$cachedUser,
        )->execute();
    }

    public function testAMatchingDebitSettlesTheBillAndPostsNoSecondCashEntry(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $bill = $this->receivedBill($vendor, 2_400.00);

        $transaction = $this->landTransaction(
            direction: BankTransactionDirectionEnum::DEBIT,
            amount: 2_400.00,
            counterpartyName: 'Globex Supply',
        );

        $outcome = new MatchBankTransactionAction($transaction, static::$cachedUser)->execute();

        $this->assertSame(BankTransactionMatchOutcomeEnum::SETTLED, $outcome);

        $bill->refresh();
        $this->assertSame(BillDocumentStatusEnum::PAID, $bill->document_status);
        $this->assertSame(0.0, round($bill->balance_due_native, 2));

        $transaction->refresh();
        $this->assertSame(BankTransactionMatchStatusEnum::AUTO_MATCHED, $transaction->match_status);
        $this->assertSame(BankTransactionMatchedToTypeEnum::BILL_PAYMENT, $transaction->matched_to_type);
        $this->assertNotNull($transaction->journal_entry_id);

        // THE invariant: a settled transaction reuses the sub-ledger's payment JE. If a bank_transaction JE
        // also exists for it, cash was credited twice.
        $this->assertSame(
            0,
            JournalEntry::query()
                ->where('apps_id', $this->kanvasApp->getId())
                ->where('source_type', 'bank_transaction')
                ->where('source_id', $transaction->id)
                ->count(),
            'A matched transaction must NOT post its own bank JE — that would double-count cash.'
        );

        // Cash moved exactly once, by exactly the amount.
        $this->assertSame(-2_400.00, $this->netMovementOn(AccountSubTypeEnum::CASH_CHECKING));
        $this->assertSame(0.0, $this->netMovementOn(AccountSubTypeEnum::SUSPENSE));
    }

    public function testAMatchingCreditSettlesTheInvoice(): void
    {
        $customer = $this->seedTestOrganization('Initech LLC');
        $invoice = $this->issueTestInvoice($customer, 5_000.00);

        $transaction = $this->landTransaction(
            direction: BankTransactionDirectionEnum::CREDIT,
            amount: 5_000.00,
            counterpartyName: 'Initech LLC',
        );

        $outcome = new MatchBankTransactionAction($transaction, static::$cachedUser)->execute();

        $this->assertSame(BankTransactionMatchOutcomeEnum::SETTLED, $outcome);

        $invoice->refresh();
        $this->assertSame(InvoiceDocumentStatusEnum::PAID, $invoice->document_status);

        $transaction->refresh();
        $this->assertSame(BankTransactionMatchedToTypeEnum::INVOICE_PAYMENT, $transaction->matched_to_type);
        $this->assertSame(2_500.00 * 2, $this->netMovementOn(AccountSubTypeEnum::CASH_CHECKING));
    }

    public function testAMatchingAmountWithTheWrongCounterpartyIsNotSettledAutomatically(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $this->receivedBill($vendor, 2_400.00);

        // Same amount, completely different counterparty. Amount alone tops out at 0.65 — below the 0.90
        // auto-settle bar — because two vendors billing the same round number is not a coincidence worth
        // marking the wrong bill paid over.
        $transaction = $this->landTransaction(
            direction: BankTransactionDirectionEnum::DEBIT,
            amount: 2_400.00,
            counterpartyName: 'Totally Different Vendor',
        );

        $outcome = new MatchBankTransactionAction($transaction, static::$cachedUser)->execute();

        $this->assertSame(BankTransactionMatchOutcomeEnum::AMBIGUOUS, $outcome);

        $transaction->refresh();
        $this->assertSame(BankTransactionMatchStatusEnum::UNMATCHED, $transaction->match_status);
        $this->assertNotEmpty($transaction->metadata['match_candidates'] ?? []);

        // Cash is still recorded — the bank moved it — but parked, not guessed.
        $this->assertSame(-2_400.00, $this->netMovementOn(AccountSubTypeEnum::CASH_CHECKING));
        $this->assertSame(2_400.00, $this->netMovementOn(AccountSubTypeEnum::SUSPENSE));
    }

    public function testTwoIdenticalBillsFromTheSameVendorAreLeftForAHuman(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $this->receivedBill($vendor, 1_000.00);
        $this->receivedBill($vendor, 1_000.00);

        $transaction = $this->landTransaction(
            direction: BankTransactionDirectionEnum::DEBIT,
            amount: 1_000.00,
            counterpartyName: 'Globex Supply',
        );

        $outcome = new MatchBankTransactionAction($transaction, static::$cachedUser)->execute();

        // Both score identically. A coin-flip is not a decision an accounting system gets to make.
        $this->assertSame(BankTransactionMatchOutcomeEnum::AMBIGUOUS, $outcome);
        $this->assertCount(2, $transaction->refresh()->metadata['match_candidates']);
    }

    public function testUnexplainedMoneyOutBooksTheCashAndFabricatesNoDocument(): void
    {
        $transaction = $this->landTransaction(
            direction: BankTransactionDirectionEnum::DEBIT,
            amount: 2_400.00,
            counterpartyName: 'AWS',
        );

        $outcome = new MatchBankTransactionAction($transaction, static::$cachedUser)->execute();

        $this->assertSame(BankTransactionMatchOutcomeEnum::REVIEW, $outcome);

        // The cash is FINAL — the bank moved it, so it posts immediately and permanently.
        $this->assertSame(-2_400.00, $this->netMovementOn(AccountSubTypeEnum::CASH_CHECKING));
        // Only the OTHER side is unknown, and that's what Suspense holds.
        $this->assertSame(2_400.00, $this->netMovementOn(AccountSubTypeEnum::SUSPENSE));

        // A Bill tracks what you owe BEFORE you pay it. The money has already gone, so there is no payable —
        // minting one would invent a liability that doesn't exist just to mark it paid a second later.
        $this->assertSame(0, Bill::query()->where('source', 'mercury')->count());
        $this->assertNull(
            Organization::query()
                ->where('companies_id', $this->company->getId())
                ->where('name', 'AWS')
                ->first(),
            'No phantom vendor either — nothing is owed to AWS.'
        );
    }

    public function testUnexplainedMoneyInBooksTheCashAndFabricatesNoInvoice(): void
    {
        $transaction = $this->landTransaction(
            direction: BankTransactionDirectionEnum::CREDIT,
            amount: 5_000.00,
            counterpartyName: 'Mystery Depositor',
        );

        $outcome = new MatchBankTransactionAction($transaction, static::$cachedUser)->execute();

        $this->assertSame(BankTransactionMatchOutcomeEnum::REVIEW, $outcome);
        $this->assertSame(0, Invoice::query()->where('source', 'mercury')->count());

        // Cash in, final. Suspense carries the unexplained counterpart until someone names it.
        $this->assertSame(5_000.00, $this->netMovementOn(AccountSubTypeEnum::CASH_CHECKING));
        $this->assertSame(-5_000.00, $this->netMovementOn(AccountSubTypeEnum::SUSPENSE));
    }

    public function testAReviewedTransactionIsResolvedByPickingAnAccountNotByApprovingADocument(): void
    {
        $transaction = $this->landTransaction(
            direction: BankTransactionDirectionEnum::DEBIT,
            amount: 2_400.00,
            counterpartyName: 'AWS',
        );

        new MatchBankTransactionAction($transaction, static::$cachedUser)->execute();

        // The entire review step: say what the other side was. No document, no approval workflow.
        $cloudHosting = Account::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('account_sub_type', AccountSubTypeEnum::CLOUD_HOSTING->value)
            ->firstOrFail();

        new ReclassifySuspenseAction(
            bankTransaction: $transaction->refresh(),
            targetAccount: $cloudHosting,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(2_400.00, $this->netMovementOn(AccountSubTypeEnum::CLOUD_HOSTING));
        $this->assertSame(0.0, $this->netMovementOn(AccountSubTypeEnum::SUSPENSE), 'Suspense drains.');
        // Cash never moved twice — it was final from the start.
        $this->assertSame(-2_400.00, $this->netMovementOn(AccountSubTypeEnum::CASH_CHECKING));
    }

    public function testAnInternalTransferSettlesNothingAndCreatesNothing(): void
    {
        $transaction = $this->landTransaction(
            direction: BankTransactionDirectionEnum::DEBIT,
            amount: 10_000.00,
            counterpartyName: 'Mercury Savings',
            category: BankTransactionCategoryEnum::TRANSFER,
        );

        $outcome = new MatchBankTransactionAction($transaction, static::$cachedUser)->execute();

        $this->assertSame(BankTransactionMatchOutcomeEnum::REVIEW, $outcome);
        $this->assertSame(
            0,
            Bill::query()->where('source', 'mercury')->count(),
            'Moving your own money between your own accounts is not a bill.'
        );
    }

    public function testABankFeeIsRecognizedWithoutAnyMatchingAttempt(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $this->receivedBill($vendor, 15.00);

        // Same amount as the open bill — but it's a known fee, so we never even look for a match.
        $transaction = $this->landTransaction(
            direction: BankTransactionDirectionEnum::DEBIT,
            amount: 15.00,
            counterpartyName: 'Mercury',
            category: BankTransactionCategoryEnum::BANK_FEE,
        );

        $outcome = new MatchBankTransactionAction($transaction, static::$cachedUser)->execute();

        $this->assertSame(BankTransactionMatchOutcomeEnum::RECOGNIZED, $outcome);
        $this->assertSame(15.00, $this->netMovementOn(AccountSubTypeEnum::BANK_FEES));
        $this->assertSame(0.0, $this->netMovementOn(AccountSubTypeEnum::SUSPENSE));
    }

    /**
     * The out-of-order case: the bank moved the money BEFORE anyone entered the vendor's invoice. The bill
     * turns up later and has to meet cash that's already sitting in Suspense.
     *
     * The trap this guards: the naive path (receive the bill, then pay it) posts DR AP / CR Cash and credits
     * cash a SECOND time for one real payment. The cash side must clear against Suspense instead.
     */
    public function testABillEnteredAfterTheCashLeftCreditsCashExactlyOnce(): void
    {
        $transaction = $this->landTransaction(
            direction: BankTransactionDirectionEnum::DEBIT,
            amount: 2_400.00,
            counterpartyName: 'AWS',
        );

        new MatchBankTransactionAction($transaction, static::$cachedUser)->execute();
        $this->assertSame(2_400.00, $this->netMovementOn(AccountSubTypeEnum::SUSPENSE));

        // Now the vendor's invoice shows up and someone enters it by hand.
        $vendor = $this->seedTestOrganization('AWS');
        $bill = $this->draftBill($vendor, 2_400.00, AccountSubTypeEnum::CLOUD_HOSTING);

        $settled = new SettleBillFromSuspenseAction(
            bankTransaction: $transaction->refresh(),
            bill: $bill,
            vendor: $vendor,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(BillDocumentStatusEnum::PAID, $settled->document_status);

        // The whole point. Cash left the bank ONCE; the books must say so exactly once.
        $this->assertSame(-2_400.00, $this->netMovementOn(AccountSubTypeEnum::CASH_CHECKING));
        $this->assertSame(2_400.00, $this->netMovementOn(AccountSubTypeEnum::CLOUD_HOSTING));

        // Suspense drained, AP settled — both back to zero.
        $this->assertSame(0.0, $this->netMovementOn(AccountSubTypeEnum::SUSPENSE));
        $this->assertSame(0.0, $this->netMovementOn(AccountSubTypeEnum::ACCOUNTS_PAYABLE));

        $this->assertSame(
            BankTransactionMatchStatusEnum::MANUALLY_MATCHED,
            $transaction->refresh()->match_status
        );
    }

    /**
     * The double-count guard between the bank feed and the Expenses sub-ledger.
     *
     * PDF ingest turns a card receipt into an Expense; approving it posts DR Expense / CR Credit Card
     * Liability. The bank feed then sees the SAME card charge. Without this guard it posts DR Suspense / CR
     * Credit Card Liability — crediting the card twice for one real charge. Nothing else catches it: the two
     * entries have different source types and different external ids, so both insert cleanly.
     */
    public function testACardChargeAlreadyBookedAsAnExpenseIsLinkedNotPostedAgain(): void
    {
        $card = new CreateBankAccountAction(
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

        // A receipt came in and was approved as an Expense — the card is already credited.
        $expense = $this->approveTestExpense(150.00, ExpensePaidByEnum::COMPANY_CARD);
        $this->assertSame(-150.00, $this->netMovementOn(AccountSubTypeEnum::CREDIT_CARD_LIABILITY));

        // Now the bank feed reports the very same charge.
        $transaction = $this->landTransaction(
            direction: BankTransactionDirectionEnum::DEBIT,
            amount: 150.00,
            counterpartyName: 'Some SaaS Vendor',
            bankAccount: $card,
        );

        $outcome = new MatchBankTransactionAction($transaction, static::$cachedUser)->execute();

        $this->assertSame(BankTransactionMatchOutcomeEnum::ALREADY_BOOKED, $outcome);

        $transaction->refresh();
        $this->assertSame(BankTransactionMatchedToTypeEnum::EXPENSE, $transaction->matched_to_type);
        $this->assertSame($expense->getId(), $transaction->matched_to_id);
        $this->assertNotNull($transaction->journal_entry_id, 'It points at the Expense\'s own JE.');

        // THE assertion. One real charge, one credit to the card. Not two.
        $this->assertSame(-150.00, $this->netMovementOn(AccountSubTypeEnum::CREDIT_CARD_LIABILITY));
        $this->assertSame(0.0, $this->netMovementOn(AccountSubTypeEnum::SUSPENSE), 'Nothing parked — it was never unexplained.');
        $this->assertSame(
            0,
            JournalEntry::query()
                ->where('apps_id', $this->kanvasApp->getId())
                ->where('source_type', 'bank_transaction')
                ->where('source_id', $transaction->id)
                ->count(),
            'The bank feed must post NO entry of its own — the Expense already booked this charge.'
        );
    }

    public function testAnUnapprovedExpenseDoesNotSuppressTheBankEntry(): void
    {
        $card = new CreateBankAccountAction(
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

        // A DRAFT expense has posted nothing. Linking to it would claim the books hold an entry they don't,
        // and the charge would silently never reach the ledger at all.
        new CreateExpenseAction(
            data: new ExpenseData(
                app: $this->kanvasApp,
                company: $this->company,
                lines: new DataCollection(ExpenseLineData::class, [
                    new ExpenseLineData(
                        description: 'Unapproved receipt',
                        amount_native: 150.00,
                        expense_account_id: $this->accountIdBySubType(AccountSubTypeEnum::SOFTWARE_SUBSCRIPTIONS),
                    ),
                ]),
                expense_date: Carbon::parse('2026-06-15'),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                paid_by: ExpensePaidByEnum::COMPANY_CARD,
            ),
            user: static::$cachedUser,
        )->execute();

        $transaction = $this->landTransaction(
            direction: BankTransactionDirectionEnum::DEBIT,
            amount: 150.00,
            counterpartyName: 'Some SaaS Vendor',
            bankAccount: $card,
        );

        $outcome = new MatchBankTransactionAction($transaction, static::$cachedUser)->execute();

        $this->assertSame(BankTransactionMatchOutcomeEnum::REVIEW, $outcome);
        // The cash IS booked — the bank moved it, whatever state the paperwork is in.
        $this->assertSame(-150.00, $this->netMovementOn(AccountSubTypeEnum::CREDIT_CARD_LIABILITY));
        $this->assertSame(150.00, $this->netMovementOn(AccountSubTypeEnum::SUSPENSE));
    }

    public function testAPartialPaymentReducesTheBalanceAndLeavesTheBillOpen(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $bill = $this->receivedBill($vendor, 41_803.00);

        // They paid 20k of a 41,803 bill. The amount alone proves nothing — every open bill over 20k "fits".
        // What makes this safe is that Globex has exactly ONE open bill, so there is nothing else it could be.
        $transaction = $this->landTransaction(
            direction: BankTransactionDirectionEnum::DEBIT,
            amount: 20_000.00,
            counterpartyName: 'Globex Supply',
        );

        $outcome = new MatchBankTransactionAction($transaction, static::$cachedUser)->execute();

        $this->assertSame(BankTransactionMatchOutcomeEnum::SETTLED_PARTIAL, $outcome);

        $bill->refresh();
        $this->assertSame(BillDocumentStatusEnum::RECEIVED, $bill->document_status, 'Still open — not paid off.');
        $this->assertSame(20_000.00, round($bill->paid_native, 2));
        $this->assertSame(21_803.00, round($bill->balance_due_native, 2));

        // Cash moved exactly what the bank said. Nothing parked.
        $this->assertSame(-20_000.00, $this->netMovementOn(AccountSubTypeEnum::CASH_CHECKING));
        $this->assertSame(0.0, $this->netMovementOn(AccountSubTypeEnum::SUSPENSE));
    }

    public function testASecondPartialPaymentFinishesTheBillOff(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $bill = $this->receivedBill($vendor, 41_803.00);

        $first = $this->landTransaction(BankTransactionDirectionEnum::DEBIT, 20_000.00, 'Globex Supply');
        new MatchBankTransactionAction($first, static::$cachedUser)->execute();

        // The remaining 21,803 now exactly clears the balance — a FULL match this time.
        $second = $this->landTransaction(BankTransactionDirectionEnum::DEBIT, 21_803.00, 'Globex Supply');
        $outcome = new MatchBankTransactionAction($second, static::$cachedUser)->execute();

        $this->assertSame(BankTransactionMatchOutcomeEnum::SETTLED, $outcome);

        $bill->refresh();
        $this->assertSame(BillDocumentStatusEnum::PAID, $bill->document_status);
        $this->assertSame(0.0, round($bill->balance_due_native, 2));
        $this->assertSame(-41_803.00, $this->netMovementOn(AccountSubTypeEnum::CASH_CHECKING));
    }

    public function testAPartialIsRefusedWhenTheVendorHasSeveralOpenBills(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $this->receivedBill($vendor, 41_803.00);
        $this->receivedBill($vendor, 30_000.00);

        // 20k fits inside BOTH bills. Which one is it paying? Nobody knows, and applying it to the wrong one
        // leaves both wrong. A mis-applied partial is worse than an unapplied one.
        $transaction = $this->landTransaction(
            direction: BankTransactionDirectionEnum::DEBIT,
            amount: 20_000.00,
            counterpartyName: 'Globex Supply',
        );

        $outcome = new MatchBankTransactionAction($transaction, static::$cachedUser)->execute();

        $this->assertSame(BankTransactionMatchOutcomeEnum::AMBIGUOUS, $outcome);
        $this->assertCount(2, $transaction->refresh()->metadata['match_candidates']);
        // Cash still booked — parked, not guessed.
        $this->assertSame(20_000.00, $this->netMovementOn(AccountSubTypeEnum::SUSPENSE));
    }

    public function testOneWireClearingSeveralInvoicesSettlesThemAllAgainstASinglePayment(): void
    {
        $customer = $this->seedTestOrganization('Initech LLC');
        $a = $this->issueTestInvoice($customer, 3_000.00);
        $b = $this->issueTestInvoice($customer, 5_000.00);
        $c = $this->issueTestInvoice($customer, 2_000.00);

        // The classic "pay everything outstanding" remittance: 3,000 + 5,000 + 2,000 = 10,000.
        $transaction = $this->landTransaction(
            direction: BankTransactionDirectionEnum::CREDIT,
            amount: 10_000.00,
            counterpartyName: 'Initech LLC',
        );

        $outcome = new MatchBankTransactionAction($transaction, static::$cachedUser)->execute();

        $this->assertSame(BankTransactionMatchOutcomeEnum::SETTLED_SPLIT, $outcome);

        foreach ([$a, $b, $c] as $invoice) {
            $this->assertSame(InvoiceDocumentStatusEnum::PAID, $invoice->refresh()->document_status);
            $this->assertSame(0.0, round($invoice->balance_due_native, 2));
        }

        // ONE bank movement is ONE payment, however many documents it covers. Three payments for one wire
        // would triple-count the cash.
        $this->assertSame(
            1,
            Payment::query()
                ->where('apps_id', $this->kanvasApp->getId())
                ->where('source', 'mercury')
                ->count(),
            'A split payment must be a single Payment allocated across the documents.'
        );

        // And cash arrived exactly once, in the right total.
        $this->assertSame(10_000.00, $this->netMovementOn(AccountSubTypeEnum::CASH_CHECKING));
        $this->assertSame(0.0, $this->netMovementOn(AccountSubTypeEnum::ACCOUNTS_RECEIVABLE));
        $this->assertSame(0.0, $this->netMovementOn(AccountSubTypeEnum::SUSPENSE));

        $this->assertCount(3, $transaction->refresh()->metadata['settled_documents']);
    }

    public function testASplitIsRefusedWhenTwoDifferentCombinationsAddUpToTheSameAmount(): void
    {
        $customer = $this->seedTestOrganization('Initech LLC');
        $this->issueTestInvoice($customer, 3_000.00);
        $this->issueTestInvoice($customer, 2_000.00);
        $this->issueTestInvoice($customer, 4_000.00);
        $this->issueTestInvoice($customer, 1_000.00);

        // No single invoice is 5,000 — but {3000 + 2000} sums to it, and so does {4000 + 1000}. Two ways to
        // reach the same number means we cannot know which invoices they actually paid, and picking one pair
        // leaves the other pair wrongly outstanding.
        $transaction = $this->landTransaction(
            direction: BankTransactionDirectionEnum::CREDIT,
            amount: 5_000.00,
            counterpartyName: 'Initech LLC',
        );

        $outcome = new MatchBankTransactionAction($transaction, static::$cachedUser)->execute();

        $this->assertSame(BankTransactionMatchOutcomeEnum::REVIEW, $outcome);
        $this->assertSame(0, Payment::query()->where('source', 'mercury')->count());
        $this->assertSame(5_000.00, $this->netMovementOn(AccountSubTypeEnum::CASH_CHECKING));
        $this->assertSame(-5_000.00, $this->netMovementOn(AccountSubTypeEnum::SUSPENSE));
    }

    /**
     * A deliberate precedence decision, pinned here because it's a judgment call worth arguing with.
     *
     * The customer has invoices of 5,000 / 3,000 / 2,000 and pays exactly 5,000. That could be the 5,000
     * invoice — or it could be 3,000 + 2,000. Both are arithmetically perfect.
     *
     * We take the single invoice. Paying one invoice in full is overwhelmingly the common case, it's what a
     * human would assume, and it's what QBO/Xero do. Refusing both would mean almost nothing ever
     * auto-matches, which trades a rare error for a useless feature.
     *
     * The residual risk is real and worth naming: if they genuinely paid the 3,000 and 2,000, we mark the
     * wrong invoice paid and leave two wrongly open. Rare, and visible in AR aging.
     */
    public function testASingleExactMatchWinsOverAPossibleSplit(): void
    {
        $customer = $this->seedTestOrganization('Initech LLC');
        $exact = $this->issueTestInvoice($customer, 5_000.00);
        $a = $this->issueTestInvoice($customer, 3_000.00);
        $b = $this->issueTestInvoice($customer, 2_000.00);

        $transaction = $this->landTransaction(
            direction: BankTransactionDirectionEnum::CREDIT,
            amount: 5_000.00,
            counterpartyName: 'Initech LLC',
        );

        $outcome = new MatchBankTransactionAction($transaction, static::$cachedUser)->execute();

        $this->assertSame(BankTransactionMatchOutcomeEnum::SETTLED, $outcome);
        $this->assertSame(InvoiceDocumentStatusEnum::PAID, $exact->refresh()->document_status);
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $a->refresh()->document_status);
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $b->refresh()->document_status);
    }

    public function testASplitNeverCombinesDocumentsFromDifferentParties(): void
    {
        $initech = $this->seedTestOrganization('Initech LLC');
        $globex = $this->seedTestOrganization('Globex Supply');
        $this->issueTestInvoice($initech, 3_000.00);
        $this->issueTestInvoice($globex, 7_000.00);

        // 3,000 + 7,000 = 10,000. Arithmetically perfect, and completely wrong — Initech did not pay Globex's
        // invoice. Without a counterparty anchor, subset-sum will confidently invent matches like this.
        $transaction = $this->landTransaction(
            direction: BankTransactionDirectionEnum::CREDIT,
            amount: 10_000.00,
            counterpartyName: 'Initech LLC',
        );

        $outcome = new MatchBankTransactionAction($transaction, static::$cachedUser)->execute();

        $this->assertSame(BankTransactionMatchOutcomeEnum::REVIEW, $outcome);
        $this->assertSame(0, Payment::query()->where('source', 'mercury')->count());
        $this->assertSame(10_000.00, $this->netMovementOn(AccountSubTypeEnum::CASH_CHECKING));
        $this->assertSame(-10_000.00, $this->netMovementOn(AccountSubTypeEnum::SUSPENSE));
    }

    public function testAnOverpaymentIsNeverAutoApplied(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $this->receivedBill($vendor, 1_000.00);

        // Paying more than is owed isn't a payment against this bill — it's an overpayment, and v1 doesn't
        // model vendor credit balances. A human decides.
        $transaction = $this->landTransaction(
            direction: BankTransactionDirectionEnum::DEBIT,
            amount: 1_500.00,
            counterpartyName: 'Globex Supply',
        );

        $outcome = new MatchBankTransactionAction($transaction, static::$cachedUser)->execute();

        $this->assertSame(BankTransactionMatchOutcomeEnum::REVIEW, $outcome);
        $this->assertSame(1_500.00, $this->netMovementOn(AccountSubTypeEnum::SUSPENSE));
    }

    public function testMatchingIsSafeToReRun(): void
    {
        $vendor = $this->seedTestOrganization('Globex Supply');
        $bill = $this->receivedBill($vendor, 2_400.00);

        $transaction = $this->landTransaction(
            direction: BankTransactionDirectionEnum::DEBIT,
            amount: 2_400.00,
            counterpartyName: 'Globex Supply',
        );

        // The webhook and the safety-net poll will both call this on the same row.
        new MatchBankTransactionAction($transaction, static::$cachedUser)->execute();
        $second = new MatchBankTransactionAction($transaction->refresh(), static::$cachedUser)->execute();

        $this->assertSame(BankTransactionMatchOutcomeEnum::ALREADY_ACCOUNTED, $second);
        $this->assertSame(0.0, round($bill->refresh()->balance_due_native, 2));
        $this->assertSame(-2_400.00, $this->netMovementOn(AccountSubTypeEnum::CASH_CHECKING));
    }

    private function landTransaction(
        BankTransactionDirectionEnum $direction,
        float $amount,
        ?string $counterpartyName = null,
        BankTransactionCategoryEnum $category = BankTransactionCategoryEnum::UNKNOWN,
        ?BankAccount $bankAccount = null,
    ): BankTransaction {
        $bankAccount ??= $this->bankAccount;
        $postedAt = Carbon::parse('2026-06-20 10:00:00');

        return new CreateBankTransactionAction(
            data: new BankTransactionData(
                app: $this->kanvasApp,
                company: $this->company,
                bankAccount: $bankAccount,
                postedAt: $postedAt,
                transactionDate: $postedAt->copy()->startOfDay(),
                direction: $direction,
                amountNative: $amount,
                currency: 'USD',
                amountBase: $amount,
                fxRateToBase: 1.0,
                category: $category,
                counterpartyName: $counterpartyName,
                memo: 'Test movement',
                source: 'mercury',
                externalId: 'txn-' . uniqid('', true),
            ),
            user: static::$cachedUser,
        )->execute();
    }

    /**
     * A bill entered by hand, still DRAFT — the vendor's invoice arrived after the cash already left.
     */
    private function draftBill(
        Organization $vendor,
        float $amount,
        AccountSubTypeEnum $expenseAccount,
    ): Bill {
        return new CreateBillAction(
            data: new BillData(
                app: $this->kanvasApp,
                company: $this->company,
                vendor: $vendor,
                lines: new DataCollection(BillLineData::class, [
                    new BillLineData(
                        description: 'Vendor invoice entered after payment',
                        quantity: 1,
                        unit_price_native: $amount,
                        expense_account_id: $this->accountIdBySubType($expenseAccount),
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                bill_date: Carbon::parse('2026-06-01'),
                due_date: Carbon::parse('2026-06-30'),
            ),
            user: static::$cachedUser,
        )->execute();
    }

    private function receivedBill(Organization $vendor, float $amount): Bill
    {
        $draft = new CreateBillAction(
            data: new BillData(
                app: $this->kanvasApp,
                company: $this->company,
                vendor: $vendor,
                lines: new DataCollection(BillLineData::class, [
                    new BillLineData(
                        description: 'Test bill line',
                        quantity: 1,
                        unit_price_native: $amount,
                        expense_account_id: $this->accountIdBySubType(AccountSubTypeEnum::OFFICE_SUPPLIES),
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                bill_date: Carbon::parse('2026-06-01'),
                due_date: Carbon::parse('2026-06-30'),
            ),
            user: static::$cachedUser,
        )->execute();

        return new ReceiveBillAction(
            bill: $draft,
            vendor: $vendor,
            user: static::$cachedUser,
        )->execute();
    }

    /**
     * Signed net movement on an account across the whole tenant: debits minus credits. Cash should end at
     * −X for money out, and Suspense at 0 once everything is explained.
     */
    private function netMovementOn(AccountSubTypeEnum $subType): float
    {
        $accountId = $this->accountIdBySubType($subType);

        $lines = JournalEntryLine::query()
            ->where('account_id', $accountId)
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
