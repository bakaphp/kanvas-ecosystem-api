<?php

declare(strict_types=1);

namespace Tests\Scribe\Banking;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Banking\Actions\CreateBankAccountAction;
use Kanvas\Scribe\Banking\Actions\CreateBankTransactionAction;
use Kanvas\Scribe\Banking\Actions\PostBankTransactionJournalEntryAction;
use Kanvas\Scribe\Banking\Actions\ReclassifySuspenseAction;
use Kanvas\Scribe\Banking\DataTransferObject\BankAccount as BankAccountData;
use Kanvas\Scribe\Banking\DataTransferObject\BankTransaction as BankTransactionData;
use Kanvas\Scribe\Banking\Enums\BankTransactionCategoryEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionDirectionEnum;
use Kanvas\Scribe\Banking\Exceptions\ExternalGlOwnershipException;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\FiscalPeriod;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Tests\Scribe\ScribeTestCase;

/**
 * PR 1 of the Mercury connector — the bank-feed foundation.
 *
 * The load-bearing assertions here are the Suspense routing (§6.1) and the no-double-posting guard: cash is
 * always booked, but an unexplained movement lands in Suspense rather than being guessed into a real account.
 */
final class BankTransactionLedgerTest extends ScribeTestCase
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
                external_id: 'acct_' . uniqid('', true),
            ),
            user: static::$cachedUser,
        )->execute();
    }

    public function testSuspenseAccountIsSeededAsAnUndeletableSystemAccount(): void
    {
        $suspense = Account::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('account_sub_type', AccountSubTypeEnum::SUSPENSE->value)
            ->first();

        $this->assertNotNull($suspense, 'Suspense must be part of the default chart of accounts.');
        $this->assertTrue((bool) $suspense->is_system);
        $this->assertSame('1900', $suspense->account_number);
    }

    public function testRePollingTheSameTransactionDoesNotDuplicateIt(): void
    {
        $externalId = 'txn_' . uniqid('', true);

        $first = new CreateBankTransactionAction(
            data: $this->transactionData(externalId: $externalId, amount: 250.00),
            user: static::$cachedUser,
        )->execute();

        // The webhook and the safety-net poll both deliver it — by design.
        $second = new CreateBankTransactionAction(
            data: $this->transactionData(externalId: $externalId, amount: 250.00),
            user: static::$cachedUser,
        )->execute();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(
            1,
            BankTransaction::query()
                ->where('apps_id', $this->kanvasApp->getId())
                ->where('source', 'mercury')
                ->where('external_id', $externalId)
                ->count()
        );
    }

    public function testUnknownMoneyOutParksInSuspenseAndCreditsCash(): void
    {
        $transaction = new CreateBankTransactionAction(
            data: $this->transactionData(
                direction: BankTransactionDirectionEnum::DEBIT,
                amount: 2400.00,
                counterpartyName: 'AWS',
            ),
            user: static::$cachedUser,
        )->execute();

        $entry = new PostBankTransactionJournalEntryAction(
            bankTransaction: $transaction,
            user: static::$cachedUser,
        )->execute();

        $this->assertJournalEntryBalances($entry);
        $this->assertLineDebits($entry, AccountSubTypeEnum::SUSPENSE, 2400.00);
        $this->assertLineCredits($entry, AccountSubTypeEnum::CASH_CHECKING, 2400.00);

        $this->assertSame($entry->id, $transaction->refresh()->journal_entry_id);
    }

    public function testUnknownMoneyInDebitsCashAndParksInSuspense(): void
    {
        $transaction = new CreateBankTransactionAction(
            data: $this->transactionData(
                direction: BankTransactionDirectionEnum::CREDIT,
                amount: 5000.00,
                counterpartyName: 'Unknown depositor',
            ),
            user: static::$cachedUser,
        )->execute();

        $entry = new PostBankTransactionJournalEntryAction(
            bankTransaction: $transaction,
            user: static::$cachedUser,
        )->execute();

        $this->assertJournalEntryBalances($entry);
        $this->assertLineDebits($entry, AccountSubTypeEnum::CASH_CHECKING, 5000.00);
        $this->assertLineCredits($entry, AccountSubTypeEnum::SUSPENSE, 5000.00);
    }

    public function testRecognizedBankFeeSkipsSuspenseAndHitsTheExpenseAccount(): void
    {
        $transaction = new CreateBankTransactionAction(
            data: $this->transactionData(
                direction: BankTransactionDirectionEnum::DEBIT,
                amount: 15.00,
                category: BankTransactionCategoryEnum::BANK_FEE,
                counterpartyName: 'Mercury',
            ),
            user: static::$cachedUser,
        )->execute();

        $entry = new PostBankTransactionJournalEntryAction(
            bankTransaction: $transaction,
            user: static::$cachedUser,
        )->execute();

        $this->assertJournalEntryBalances($entry);
        $this->assertLineDebits($entry, AccountSubTypeEnum::BANK_FEES, 15.00);
        $this->assertLineCredits($entry, AccountSubTypeEnum::CASH_CHECKING, 15.00);
        $this->assertNoLineFor($entry, AccountSubTypeEnum::SUSPENSE);
    }

    public function testRecognizedInterestIncomeCreditsTheIncomeAccount(): void
    {
        $transaction = new CreateBankTransactionAction(
            data: $this->transactionData(
                direction: BankTransactionDirectionEnum::CREDIT,
                amount: 42.50,
                category: BankTransactionCategoryEnum::INTEREST_INCOME,
                counterpartyName: 'Mercury',
            ),
            user: static::$cachedUser,
        )->execute();

        $entry = new PostBankTransactionJournalEntryAction(
            bankTransaction: $transaction,
            user: static::$cachedUser,
        )->execute();

        $this->assertJournalEntryBalances($entry);
        $this->assertLineDebits($entry, AccountSubTypeEnum::CASH_CHECKING, 42.50);
        $this->assertLineCredits($entry, AccountSubTypeEnum::INTEREST_INCOME, 42.50);
        $this->assertNoLineFor($entry, AccountSubTypeEnum::SUSPENSE);
    }

    public function testPostingTwiceReusesTheSameJournalEntry(): void
    {
        $transaction = new CreateBankTransactionAction(
            data: $this->transactionData(amount: 99.00),
            user: static::$cachedUser,
        )->execute();

        $first = new PostBankTransactionJournalEntryAction($transaction, static::$cachedUser)->execute();
        $second = new PostBankTransactionJournalEntryAction($transaction->refresh(), static::$cachedUser)->execute();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(
            1,
            JournalEntry::query()
                ->where('apps_id', $this->kanvasApp->getId())
                ->where('source_type', 'bank_transaction')
                ->where('source_id', $transaction->id)
                ->count()
        );
    }

    public function testReclassifyingSuspenseMovesTheAmountToTheRealAccountAndNeverTouchesCash(): void
    {
        $transaction = new CreateBankTransactionAction(
            data: $this->transactionData(
                direction: BankTransactionDirectionEnum::DEBIT,
                amount: 2400.00,
                counterpartyName: 'AWS',
            ),
            user: static::$cachedUser,
        )->execute();

        new PostBankTransactionJournalEntryAction($transaction, static::$cachedUser)->execute();

        $cloudHosting = Account::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('account_sub_type', AccountSubTypeEnum::CLOUD_HOSTING->value)
            ->firstOrFail();

        $reclass = new ReclassifySuspenseAction(
            bankTransaction: $transaction->refresh(),
            targetAccount: $cloudHosting,
            user: static::$cachedUser,
        )->execute();

        $this->assertJournalEntryBalances($reclass);
        $this->assertLineDebits($reclass, AccountSubTypeEnum::CLOUD_HOSTING, 2400.00);
        $this->assertLineCredits($reclass, AccountSubTypeEnum::SUSPENSE, 2400.00);
        $this->assertNoLineFor($reclass, AccountSubTypeEnum::CASH_CHECKING);

        // Suspense nets to zero across the two entries — the inbox drained.
        $this->assertSame(0.0, $this->netBalanceFor(AccountSubTypeEnum::SUSPENSE, $transaction->id));
    }

    public function testReclassifyingTwiceDoesNotDoubleDrainSuspense(): void
    {
        $transaction = new CreateBankTransactionAction(
            data: $this->transactionData(direction: BankTransactionDirectionEnum::DEBIT, amount: 100.00),
            user: static::$cachedUser,
        )->execute();

        new PostBankTransactionJournalEntryAction($transaction, static::$cachedUser)->execute();

        $rent = Account::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('account_sub_type', AccountSubTypeEnum::RENT->value)
            ->firstOrFail();

        $first = new ReclassifySuspenseAction($transaction->refresh(), $rent, static::$cachedUser)->execute();
        $second = new ReclassifySuspenseAction($transaction->refresh(), $rent, static::$cachedUser)->execute();

        $this->assertSame($first->id, $second->id);
        $this->assertSame(0.0, $this->netBalanceFor(AccountSubTypeEnum::SUSPENSE, $transaction->id));
    }

    public function testABankDateOutsideAnyOpenPeriodAutoOpensThatMonth(): void
    {
        // The seeded period covers June 2026 only. A real bank feed does not care.
        $septemberDate = Carbon::parse('2026-09-17 09:30:00');

        $this->assertFalse(
            $this->periodExistsCovering($septemberDate),
            'Precondition: September 2026 must have no fiscal period yet.'
        );

        $transaction = new CreateBankTransactionAction(
            data: $this->transactionData(amount: 310.00, postedAt: $septemberDate),
            user: static::$cachedUser,
        )->execute();

        $entry = new PostBankTransactionJournalEntryAction($transaction, static::$cachedUser)->execute();

        $this->assertJournalEntryBalances($entry);
        $this->assertTrue(
            $this->periodExistsCovering($septemberDate),
            'Posting a bank transaction into an unopened month must auto-open that calendar month.'
        );
    }

    public function testItRefusesToPostWhenAnErpOwnsTheGeneralLedger(): void
    {
        $transaction = new CreateBankTransactionAction(
            data: $this->transactionData(amount: 500.00),
            user: static::$cachedUser,
        )->execute();

        // Acumatica imports its own AP/AR/cash batches as journal entries, so the cash is already booked.
        $this->company->set('ACUMATICA_SYNC_ENABLED', true);

        try {
            $this->expectException(ExternalGlOwnershipException::class);

            new PostBankTransactionJournalEntryAction($transaction, static::$cachedUser)->execute();
        } finally {
            $this->company->set('ACUMATICA_SYNC_ENABLED', false);
        }
    }

    private function transactionData(
        BankTransactionDirectionEnum $direction = BankTransactionDirectionEnum::DEBIT,
        float $amount = 100.00,
        BankTransactionCategoryEnum $category = BankTransactionCategoryEnum::UNKNOWN,
        ?string $counterpartyName = 'Acme Vendor',
        ?string $externalId = null,
        ?Carbon $postedAt = null,
    ): BankTransactionData {
        $postedAt ??= Carbon::parse('2026-06-15 10:00:00');

        return new BankTransactionData(
            app: $this->kanvasApp,
            company: $this->company,
            bankAccount: $this->bankAccount,
            postedAt: $postedAt,
            transactionDate: $postedAt->copy()->startOfDay(),
            direction: $direction,
            amountNative: $amount,
            currency: 'USD',
            amountBase: $amount,
            fxRateToBase: 1.0,
            category: $category,
            counterpartyName: $counterpartyName,
            memo: 'Test bank movement',
            rawPayload: ['id' => $externalId, 'amount' => $amount],
            source: 'mercury',
            externalId: $externalId ?? 'txn_' . uniqid('', true),
        );
    }

    private function assertJournalEntryBalances(JournalEntry $entry): void
    {
        $entry->loadMissing('lines');

        $debits = round((float) $entry->lines->sum('debit_base'), 4);
        $credits = round((float) $entry->lines->sum('credit_base'), 4);

        $this->assertSame($debits, $credits, 'Journal entry must balance: SUM(debit_base) == SUM(credit_base).');
        $this->assertGreaterThan(0.0, $debits, 'Journal entry must actually move money.');
    }

    private function assertLineDebits(JournalEntry $entry, AccountSubTypeEnum $subType, float $amount): void
    {
        $line = $this->lineFor($entry, $subType);

        $this->assertNotNull($line, "Expected a line on '{$subType->value}'.");
        $this->assertSame($amount, round((float) $line->debit_native, 2));
        $this->assertSame(0.0, round((float) $line->credit_native, 2));
    }

    private function assertLineCredits(JournalEntry $entry, AccountSubTypeEnum $subType, float $amount): void
    {
        $line = $this->lineFor($entry, $subType);

        $this->assertNotNull($line, "Expected a line on '{$subType->value}'.");
        $this->assertSame($amount, round((float) $line->credit_native, 2));
        $this->assertSame(0.0, round((float) $line->debit_native, 2));
    }

    private function assertNoLineFor(JournalEntry $entry, AccountSubTypeEnum $subType): void
    {
        $this->assertNull(
            $this->lineFor($entry, $subType),
            "Did not expect a line on '{$subType->value}'."
        );
    }

    private function lineFor(JournalEntry $entry, AccountSubTypeEnum $subType): mixed
    {
        $accountId = $this->accountIdBySubType($subType);
        $entry->loadMissing('lines');

        return $entry->lines->firstWhere('account_id', $accountId);
    }

    /**
     * Net movement on an account across every JE this bank transaction produced. Zero on Suspense means the
     * park + reclass cancelled out.
     */
    private function netBalanceFor(AccountSubTypeEnum $subType, int $bankTransactionId): float
    {
        $accountId = $this->accountIdBySubType($subType);

        $lines = JournalEntry::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('source_type', 'bank_transaction')
            ->where('source_id', $bankTransactionId)
            ->with('lines')
            ->get()
            ->flatMap(fn (JournalEntry $entry) => $entry->lines)
            ->where('account_id', $accountId);

        return round((float) $lines->sum('debit_base') - (float) $lines->sum('credit_base'), 4);
    }

    private function periodExistsCovering(Carbon $date): bool
    {
        return FiscalPeriod::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->whereDate('period_start', '<=', $date)
            ->whereDate('period_end', '>=', $date)
            ->exists();
    }
}
