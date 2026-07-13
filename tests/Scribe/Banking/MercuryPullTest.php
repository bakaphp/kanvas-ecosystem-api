<?php

declare(strict_types=1);

namespace Tests\Scribe\Banking;

use Kanvas\Connectors\Mercury\Actions\PullMercuryAccountsAction;
use Kanvas\Connectors\Mercury\Actions\PullMercuryCardsAction;
use Kanvas\Connectors\Mercury\Actions\PullMercuryStatementsAction;
use Kanvas\Connectors\Mercury\Actions\PullMercuryTransactionsAction;
use Kanvas\Connectors\Mercury\Enums\TransactionKindEnum;
use Kanvas\Connectors\Mercury\Services\MercuryAccountService;
use Kanvas\Connectors\Mercury\Services\MercuryCardService;
use Kanvas\Connectors\Mercury\Services\MercuryStatementService;
use Kanvas\Connectors\Mercury\Services\MercuryTransactionService;
use Kanvas\Scribe\Banking\Enums\BankTransactionCategoryEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionDirectionEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionMatchStatusEnum;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Tests\Connectors\Traits\HasMercuryConfiguration;
use Tests\Scribe\ScribeTestCase;

/**
 * PR 2 — the Mercury connector's ingest path.
 *
 * The assertion that matters most is the last one: ingest lands rows but posts NO journal entries. Whether
 * a movement gets its own JE depends on whether it settles a bill or invoice, and that is the matcher's
 * decision (PR 3). Posting at ingest time would send every incoming customer payment to Suspense.
 */
final class MercuryPullTest extends ScribeTestCase
{
    use HasMercuryConfiguration;

    private const string MERCURY_ACCOUNT_ID = 'acct-11111111-2222-3333-4444-555555555555';

    protected function afterScribeSetUp(): void
    {
        $this->configureMercury($this->company);
    }

    public function testItMirrorsMercuryAccountsIntoBankAccountsAndSkipsCounterparties(): void
    {
        $accounts = $this->syncAccounts([
            'accounts' => [
                $this->mercuryAccountPayload(self::MERCURY_ACCOUNT_ID, currentBalance: 42_500.75),
                // Counterparties Mercury tracks for us — not our cash, must never become bank accounts.
                $this->mercuryAccountPayload('acct-external', type: 'external'),
                $this->mercuryAccountPayload('acct-recipient', type: 'recipient'),
                $this->mercuryAccountPayload('acct-archived', status: 'archived'),
            ],
        ]);

        $this->assertCount(1, $accounts);

        $bankAccount = $accounts[0];
        $this->assertSame('mercury', $bankAccount->source);
        $this->assertSame(self::MERCURY_ACCOUNT_ID, $bankAccount->external_id);
        $this->assertSame('USD', $bankAccount->currency);
        $this->assertSame('1234', $bankAccount->account_number_last4);
        $this->assertSame(42_500.75, (float) $bankAccount->current_balance_native);
        $this->assertNotNull($bankAccount->last_balance_sync_at);
    }

    public function testReSyncingAccountsRefreshesBalancesWithoutDuplicating(): void
    {
        $this->syncAccounts([
            'accounts' => [$this->mercuryAccountPayload(self::MERCURY_ACCOUNT_ID, currentBalance: 1_000.00)],
        ]);

        $second = $this->syncAccounts([
            'accounts' => [$this->mercuryAccountPayload(self::MERCURY_ACCOUNT_ID, currentBalance: 7_777.00)],
        ]);

        $this->assertSame(7_777.00, (float) $second[0]->current_balance_native);
        $this->assertSame(
            1,
            BankAccount::query()
                ->where('apps_id', $this->kanvasApp->getId())
                ->where('source', 'mercury')
                ->where('external_id', self::MERCURY_ACCOUNT_ID)
                ->count()
        );
    }

    public function testASignedNegativeAmountBecomesADebitAndAPositiveBecomesACredit(): void
    {
        $bankAccount = $this->seedBankAccount();

        $landed = $this->syncTransactions($bankAccount, [
            'transactions' => [
                // Mercury sends money-out as a NEGATIVE amount. Scribe wants direction + magnitude.
                $this->mercuryTransactionPayload('txn-out', self::MERCURY_ACCOUNT_ID, -2_400.00),
                $this->mercuryTransactionPayload('txn-in', self::MERCURY_ACCOUNT_ID, 5_000.00),
            ],
            'page' => ['nextPage' => null],
        ]);

        $this->assertCount(2, $landed);

        $out = $this->findTransaction('txn-out');
        $this->assertSame(BankTransactionDirectionEnum::DEBIT, $out->direction);
        $this->assertSame(2_400.00, $out->amount_native, 'Amount must be stored as a positive magnitude.');

        $in = $this->findTransaction('txn-in');
        $this->assertSame(BankTransactionDirectionEnum::CREDIT, $in->direction);
        $this->assertSame(5_000.00, $in->amount_native);
    }

    public function testItIngestsOnlySettledTransactions(): void
    {
        $bankAccount = $this->seedBankAccount();

        $landed = $this->syncTransactions($bankAccount, [
            'transactions' => [
                $this->mercuryTransactionPayload('txn-sent', self::MERCURY_ACCOUNT_ID, -100.00, status: 'sent'),
                // A pending auth can still cancel or settle at a different amount — booking it would put
                // cash on the ledger the bank never moved.
                $this->mercuryTransactionPayload('txn-pending', self::MERCURY_ACCOUNT_ID, -50.00, status: 'pending'),
                $this->mercuryTransactionPayload('txn-failed', self::MERCURY_ACCOUNT_ID, -75.00, status: 'failed'),
            ],
            'page' => ['nextPage' => null],
        ]);

        $this->assertCount(1, $landed);
        $this->assertSame('txn-sent', $landed[0]->external_id);
        $this->assertNull($this->findTransaction('txn-pending'));
        $this->assertNull($this->findTransaction('txn-failed'));
    }

    public function testFeeKindsClassifyAsBankFeeAndPaymentsStayUnknownForTheMatcher(): void
    {
        $bankAccount = $this->seedBankAccount();

        $this->syncTransactions($bankAccount, [
            'transactions' => [
                $this->mercuryTransactionPayload('txn-fee', self::MERCURY_ACCOUNT_ID, -15.00, kind: 'wireFee'),
                $this->mercuryTransactionPayload('txn-xfer', self::MERCURY_ACCOUNT_ID, -500.00, kind: 'internalTransfer'),
                $this->mercuryTransactionPayload('txn-pay', self::MERCURY_ACCOUNT_ID, -2400.00, kind: 'outgoingPayment'),
                $this->mercuryTransactionPayload('txn-wire-in', self::MERCURY_ACCOUNT_ID, 9000.00, kind: 'incomingDomesticWire'),
            ],
            'page' => ['nextPage' => null],
        ]);

        $this->assertSame(BankTransactionCategoryEnum::BANK_FEE, $this->findTransaction('txn-fee')->category);
        $this->assertSame(BankTransactionCategoryEnum::TRANSFER, $this->findTransaction('txn-xfer')->category);

        // These are exactly the movements that SHOULD settle a bill/invoice. Classifying them would rob the
        // matcher of the chance — they must stay UNKNOWN.
        $this->assertSame(BankTransactionCategoryEnum::UNKNOWN, $this->findTransaction('txn-pay')->category);
        $this->assertSame(BankTransactionCategoryEnum::UNKNOWN, $this->findTransaction('txn-wire-in')->category);
    }

    public function testAnUnrecognizedKindDoesNotThrowAndFallsBackToUnknown(): void
    {
        // Mercury can ship a kind we've never seen. That's unclassified cash, not an error.
        $this->assertSame(
            BankTransactionCategoryEnum::UNKNOWN,
            TransactionKindEnum::categoryFor('someBrandNewKindMercuryJustShipped')
        );
        $this->assertSame(BankTransactionCategoryEnum::UNKNOWN, TransactionKindEnum::categoryFor(null));
    }

    public function testReSyncingTheSameTransactionsDoesNotDuplicateThem(): void
    {
        $bankAccount = $this->seedBankAccount();

        $payload = [
            'transactions' => [
                $this->mercuryTransactionPayload('txn-dupe', self::MERCURY_ACCOUNT_ID, -300.00),
            ],
            'page' => ['nextPage' => null],
        ];

        $this->syncTransactions($bankAccount, $payload);
        $this->syncTransactions($bankAccount, $payload);

        $this->assertSame(
            1,
            BankTransaction::query()
                ->where('apps_id', $this->kanvasApp->getId())
                ->where('source', 'mercury')
                ->where('external_id', 'txn-dupe')
                ->count()
        );
    }

    public function testIngestPostsNoJournalEntries(): void
    {
        $bankAccount = $this->seedBankAccount();

        $before = $this->journalEntryCount();

        $landed = $this->syncTransactions($bankAccount, [
            'transactions' => [
                $this->mercuryTransactionPayload('txn-a', self::MERCURY_ACCOUNT_ID, -2400.00, kind: 'outgoingPayment'),
                $this->mercuryTransactionPayload('txn-b', self::MERCURY_ACCOUNT_ID, 5000.00, kind: 'incomingDomesticWire'),
                $this->mercuryTransactionPayload('txn-c', self::MERCURY_ACCOUNT_ID, -15.00, kind: 'wireFee'),
            ],
            'page' => ['nextPage' => null],
        ]);

        $this->assertCount(3, $landed);
        $this->assertSame(
            $before,
            $this->journalEntryCount(),
            'Ingest must post no JEs — that is the matcher\'s decision in PR 3.'
        );

        foreach ($landed as $transaction) {
            $this->assertNull($transaction->journal_entry_id);
            $this->assertSame(BankTransactionMatchStatusEnum::UNMATCHED, $transaction->match_status);
        }
    }

    public function testTheCreditCardAccountIsPulledAndBackedByALiabilityAccount(): void
    {
        // The card lives behind a separate endpoint and never appears in /accounts. Pulling only /accounts
        // would silently omit every card transaction — for a company that puts its SaaS spend on the card,
        // that's most of the operating expense.
        $accounts = $this->syncAccounts(
            ['accounts' => [$this->mercuryAccountPayload(self::MERCURY_ACCOUNT_ID)]],
            ['accounts' => [[
                'id' => 'credit-9999',
                'status' => 'active',
                'createdAt' => '2024-10-29T16:21:04Z',
                // Negative = money owed. The mirror of a deposit account.
                'currentBalance' => -1_130.55,
                'availableBalance' => -1_130.55,
            ]]],
        );

        $this->assertCount(2, $accounts);

        $card = collect($accounts)->firstWhere('external_id', 'credit-9999');
        $this->assertNotNull($card);
        $this->assertSame('Mercury Credit', $card->account_name);

        // A credit card is not cash — it's what you owe, so it backs a LIABILITY account.
        $this->assertSame(
            AccountSubTypeEnum::CREDIT_CARD_LIABILITY,
            $card->glAccount->account_sub_type
        );
        $this->assertSame(-1_130.55, (float) $card->current_balance_native);
    }

    public function testStatementsAreNotRequestedForACreditCard(): void
    {
        // Mercury's v1 statements endpoint 404s for credit accounts — statements are v2-only for cards. The
        // client below has NO canned response, so any request at all would blow up the mock.
        $card = collect($this->syncAccounts(
            ['accounts' => []],
            ['accounts' => [[
                'id' => 'credit-9999',
                'status' => 'active',
                'createdAt' => '2024-10-29T16:21:04Z',
                'currentBalance' => -1_130.55,
                'availableBalance' => -1_130.55,
            ]]],
        ))->firstWhere('external_id', 'credit-9999');

        $statements = new PullMercuryStatementsAction(
            bankAccount: $card,
            statementService: new MercuryStatementService(
                $this->kanvasApp,
                $this->company,
                $this->mercuryClientReturning($this->kanvasApp, $this->company, []),
            ),
        )->execute();

        $this->assertSame([], $statements);
    }

    public function testPayingOurOwnCreditCardIsClassifiedAsATransfer(): void
    {
        // Mercury reports a card payment as the useless kind:"other" with the card's own name as the
        // counterparty. Left UNKNOWN it looks like a payment to an outside vendor, when in fact no money left
        // the business at all — it moved from cash to the card liability.
        $accounts = $this->syncAccounts(
            ['accounts' => [$this->mercuryAccountPayload(self::MERCURY_ACCOUNT_ID)]],
            ['accounts' => [[
                'id' => 'credit-9999',
                'status' => 'active',
                'currentBalance' => -500.00,
                'availableBalance' => -500.00,
            ]]],
        );

        $checking = collect($accounts)->firstWhere('external_id', self::MERCURY_ACCOUNT_ID);

        $this->syncTransactions($checking, [
            'transactions' => [
                $this->mercuryTransactionPayload(
                    'txn-cardpay',
                    self::MERCURY_ACCOUNT_ID,
                    -2_758.63,
                    kind: 'other',
                    counterpartyName: 'Mercury Credit',
                ),
                $this->mercuryTransactionPayload(
                    'txn-realvendor',
                    self::MERCURY_ACCOUNT_ID,
                    -900.00,
                    kind: 'other',
                    counterpartyName: 'Some Real Vendor',
                ),
            ],
            'page' => ['nextPage' => null],
        ]);

        $this->assertSame(
            BankTransactionCategoryEnum::TRANSFER,
            $this->findTransaction('txn-cardpay')->category,
            'Money moving to an account we own is a transfer, never a spend.'
        );

        // A genuine outside vendor must still be left UNKNOWN so the matcher gets its shot.
        $this->assertSame(
            BankTransactionCategoryEnum::UNKNOWN,
            $this->findTransaction('txn-realvendor')->category
        );
    }

    public function testItSyncsCardsOntoTheBankAccount(): void
    {
        $bankAccount = $this->seedBankAccount();

        $cards = new PullMercuryCardsAction(
            bankAccount: $bankAccount,
            cardService: new MercuryCardService(
                $this->kanvasApp,
                $this->company,
                $this->mercuryClientReturning($this->kanvasApp, $this->company, [[
                    'cards' => [[
                        'cardId' => 'card-1',
                        'lastFourDigits' => '4242',
                        'nameOnCard' => 'Test Cardholder',
                        'network' => 'visa',
                        'status' => 'active',
                        'type' => 'physical',
                        'userId' => 'user-1',
                        'createdAt' => '2026-01-01T00:00:00Z',
                        'updatedAt' => '2026-01-01T00:00:00Z',
                    ]],
                ]]),
            ),
        )->execute();

        $this->assertCount(1, $cards);
        $this->assertSame('4242', $cards[0]['last_four']);
        $this->assertSame('card-1', $bankAccount->refresh()->metadata['cards'][0]['card_id']);
    }

    /**
     * The account pull makes TWO calls — /accounts then /credit — so both need a canned response.
     *
     * @return list<BankAccount>
     */
    private function syncAccounts(array $response, array $creditResponse = ['accounts' => []]): array
    {
        return new PullMercuryAccountsAction(
            app: $this->kanvasApp,
            company: $this->company,
            user: static::$cachedUser,
            accountService: new MercuryAccountService(
                $this->kanvasApp,
                $this->company,
                $this->mercuryClientReturning(
                    $this->kanvasApp,
                    $this->company,
                    [$response, $creditResponse],
                ),
            ),
        )->execute();
    }

    /**
     * @return list<BankTransaction>
     */
    private function syncTransactions(BankAccount $bankAccount, array $response): array
    {
        return new PullMercuryTransactionsAction(
            bankAccount: $bankAccount,
            user: static::$cachedUser,
            transactionService: new MercuryTransactionService(
                $this->kanvasApp,
                $this->company,
                $this->mercuryClientReturning($this->kanvasApp, $this->company, [$response]),
            ),
        )->execute();
    }

    private function seedBankAccount(): BankAccount
    {
        return $this->syncAccounts([
            'accounts' => [$this->mercuryAccountPayload(self::MERCURY_ACCOUNT_ID)],
        ])[0];
    }

    private function findTransaction(string $externalId): ?BankTransaction
    {
        return BankTransaction::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('source', 'mercury')
            ->where('external_id', $externalId)
            ->first();
    }

    private function journalEntryCount(): int
    {
        return JournalEntry::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->count();
    }
}
