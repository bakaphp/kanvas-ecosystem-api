<?php

declare(strict_types=1);

namespace Tests\Scribe\Banking;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Banking\Actions\CreateBankAccountAction;
use Kanvas\Scribe\Banking\Actions\CreateBankTransactionAction;
use Kanvas\Scribe\Banking\Actions\MatchBankTransactionAction;
use Kanvas\Scribe\Banking\Actions\PostOpeningBalanceAction;
use Kanvas\Scribe\Banking\DataTransferObject\BankAccount as BankAccountData;
use Kanvas\Scribe\Banking\DataTransferObject\BankTransaction as BankTransactionData;
use Kanvas\Scribe\Banking\Enums\BankTransactionDirectionEnum;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\Ledger\Models\JournalEntryLine;
use Tests\Scribe\ScribeTestCase;

/**
 * A feed only imports a WINDOW of history, so the GL reflects movement inside that window — not the account's
 * real balance. Without an opening entry, Cash reads −8,020 while the bank says 17,761, and every balance
 * sheet is wrong.
 */
final class OpeningBalanceTest extends ScribeTestCase
{
    private BankAccount $bankAccount;

    protected function afterScribeSetUp(): void
    {
        $this->bankAccount = $this->makeBankAccount(AccountSubTypeEnum::CASH_CHECKING, 'Mercury Checking');
    }

    public function testItAnchorsTheGlToWhatTheBankActuallySays(): void
    {
        // Imported window: 1,000 in, 3,000 out → GL nets to −2,000.
        $this->landAndPost(BankTransactionDirectionEnum::CREDIT, 1_000.00);
        $this->landAndPost(BankTransactionDirectionEnum::DEBIT, 3_000.00);
        $this->assertSame(-2_000.00, $this->glBalance(AccountSubTypeEnum::CASH_CHECKING));

        // But the bank says the account actually holds 17,761.88 — the rest was there before our window.
        new PostOpeningBalanceAction(
            bankAccount: $this->bankAccount,
            bankBalance: 17_761.88,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(17_761.88, $this->glBalance(AccountSubTypeEnum::CASH_CHECKING));
        // The 19,761.88 that was already sitting there lands in Opening Balance Equity.
        $this->assertSame(-19_761.88, $this->glBalance(AccountSubTypeEnum::OPENING_BALANCE_EQUITY));
    }

    public function testItIsSafeToReRunAndPostsNothingWhenNothingChanged(): void
    {
        $this->landAndPost(BankTransactionDirectionEnum::DEBIT, 500.00);

        $first = new PostOpeningBalanceAction($this->bankAccount, 10_000.00, static::$cachedUser)->execute();
        $this->assertNotNull($first);

        // Re-running against an unchanged history must not double the equity.
        $second = new PostOpeningBalanceAction($this->bankAccount, 10_000.00, static::$cachedUser)->execute();

        $this->assertNull($second, 'A second run with no change must post nothing.');
        $this->assertSame(10_000.00, $this->glBalance(AccountSubTypeEnum::CASH_CHECKING));
    }

    public function testBackfillingOlderHistoryTopsUpRatherThanCorruptingTheOpening(): void
    {
        $this->landAndPost(BankTransactionDirectionEnum::DEBIT, 500.00);
        new PostOpeningBalanceAction($this->bankAccount, 10_000.00, static::$cachedUser)->execute();
        $this->assertSame(10_000.00, $this->glBalance(AccountSubTypeEnum::CASH_CHECKING));

        // Someone widens the lookback and older transactions arrive. The true opening has shifted.
        $this->landAndPost(BankTransactionDirectionEnum::DEBIT, 2_000.00);
        $this->assertSame(8_000.00, $this->glBalance(AccountSubTypeEnum::CASH_CHECKING));

        // Re-running posts a top-up for the delta — the ledger is immutable, so we never edit the original.
        new PostOpeningBalanceAction($this->bankAccount, 10_000.00, static::$cachedUser)->execute();

        $this->assertSame(10_000.00, $this->glBalance(AccountSubTypeEnum::CASH_CHECKING));
    }

    public function testACreditCardOpeningNeedsNoSpecialCasing(): void
    {
        $card = $this->makeBankAccount(AccountSubTypeEnum::CREDIT_CARD_LIABILITY, 'Mercury Credit');

        // Card spend in the window: 300 out → credits (increases) the liability.
        $this->landAndPost(BankTransactionDirectionEnum::DEBIT, 300.00, $card);
        $this->assertSame(-300.00, $this->glBalance(AccountSubTypeEnum::CREDIT_CARD_LIABILITY));

        // Mercury reports a card balance as NEGATIVE when money is owed — already the sign a liability
        // wants (a credit balance), so the same arithmetic works with no branch.
        new PostOpeningBalanceAction($card, -1_130.55, static::$cachedUser)->execute();

        $this->assertSame(-1_130.55, $this->glBalance(AccountSubTypeEnum::CREDIT_CARD_LIABILITY));
    }

    private function makeBankAccount(AccountSubTypeEnum $glSubType, string $name): BankAccount
    {
        return new CreateBankAccountAction(
            data: new BankAccountData(
                app: $this->kanvasApp,
                company: $this->company,
                account_name: $name,
                gl_account_id: $this->accountIdBySubType($glSubType),
                currency: 'USD',
                institution_name: 'Mercury',
                source: 'mercury',
                external_id: 'acct-' . uniqid('', true),
            ),
            user: static::$cachedUser,
        )->execute();
    }

    private function landAndPost(
        BankTransactionDirectionEnum $direction,
        float $amount,
        ?BankAccount $bankAccount = null,
    ): void {
        $bankAccount ??= $this->bankAccount;
        $postedAt = Carbon::parse('2026-06-15 10:00:00');

        $transaction = new CreateBankTransactionAction(
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
                counterpartyName: 'Someone',
                source: 'mercury',
                externalId: 'txn-' . uniqid('', true),
            ),
            user: static::$cachedUser,
        )->execute();

        new MatchBankTransactionAction($transaction, static::$cachedUser)->execute();
    }

    private function glBalance(AccountSubTypeEnum $subType): float
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
