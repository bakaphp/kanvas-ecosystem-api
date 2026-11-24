<?php

declare(strict_types=1);

namespace Tests\Connectors\Brex;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Brex\Actions\PullBrexAccountsAction;
use Kanvas\Connectors\Brex\Actions\PullBrexTransactionsAction;
use Kanvas\Connectors\Brex\Client;
use Kanvas\Connectors\Brex\DataTransferObject\BrexAccount;
use Kanvas\Connectors\Brex\DataTransferObject\BrexTransaction;
use Kanvas\Connectors\Brex\Enums\ConfigurationEnum;
use Kanvas\Connectors\Brex\Handlers\BrexHandler;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Regions\Models\Regions;
use Kanvas\Scribe\Banking\Actions\CreateBankAccountAction;
use Kanvas\Scribe\Banking\DataTransferObject\BankAccount as BankAccountData;
use Kanvas\Scribe\Banking\Enums\BankTransactionCategoryEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionDirectionEnum;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Tests\Scribe\ScribeTestCase;

/**
 * Brex is read-only here: a bank feed (cash + card) into Scribe. Every payload is synthetic.
 *
 * One file on purpose: the token lives in company settings, which are shared by every paratest process, and a
 * second Brex test file running in parallel could rotate or remove it under this one.
 */
final class BrexConnectorTest extends ScribeTestCase
{
    private const string TOKEN = 'test-brex-token';

    private string $cashAccountId;

    protected function afterScribeSetUp(): void
    {
        $this->company->set(ConfigurationEnum::API_TOKEN->value, self::TOKEN);
        $this->company->set(ConfigurationEnum::SYNC_ENABLED->value, '1');

        $this->cashAccountId = 'cash_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach ([
            ConfigurationEnum::API_TOKEN->value,
            ConfigurationEnum::SYNC_ENABLED->value,
            ConfigurationEnum::SYNC_APP_ID->value,
            ConfigurationEnum::SYNC_CURSOR->forAccount($this->cashAccountId),
            ConfigurationEnum::SYNC_CURSOR->forAccount(BrexAccount::CARD_EXTERNAL_ID),
        ] as $key) {
            $this->company->del($key);
        }

        parent::tearDown();
    }

    public function testTheScheduledPullLandsTheWholeFeedForOneCompany(): void
    {
        $this->company->set(ConfigurationEnum::SYNC_APP_ID->value, (string) $this->kanvasApp->getId());

        Http::fake([
            'api.brex.com/v2/accounts/cash*' => Http::response([
                'items' => [$this->cashAccountPayload($this->cashAccountId)],
                'next_cursor' => null,
            ]),
            'api.brex.com/v2/accounts/card*' => Http::response([]),
            'api.brex.com/v2/transactions/cash/*' => Http::response([
                'items' => [$this->cashTransactionPayload($this->cashAccountId . '-fee', -1_500, 'FEE')],
                'next_cursor' => null,
            ]),
        ]);

        $this->artisan('kanvas:brex-pull', ['--company_id' => $this->company->getId()])
            ->assertSuccessful();

        $fee = BankTransaction::query()->where('source', 'brex')->where('external_id', $this->cashAccountId . '-fee')->firstOrFail();
        $this->assertSame(BankTransactionCategoryEnum::BANK_FEE, $fee->category);
        $this->assertTrue($fee->refresh()->isAccountedFor(), 'A known bank fee is booked by the matcher.');
    }

    public function testTheClientSendsTheTokenAndWalksEveryPage(): void
    {
        Http::fake([
            'api.brex.com/v2/accounts/cash*' => Http::sequence()
                ->push(['items' => [$this->cashAccountPayload('cash_a')], 'next_cursor' => 'page-2'])
                ->push(['items' => [$this->cashAccountPayload('cash_b')], 'next_cursor' => null]),
        ]);

        $items = new Client($this->kanvasApp, $this->company)->getAllPages('v2/accounts/cash');

        $this->assertSame(['cash_a', 'cash_b'], array_column($items, 'id'));

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer ' . self::TOKEN)
            && str_starts_with($request->url(), ConfigurationEnum::BASE_URL . '/v2/accounts/cash'));
        Http::assertSent(fn (Request $request): bool => ($request->data()['cursor'] ?? null) === 'page-2');
    }

    public function testSetupVerifiesTheTokenWithACheapRead(): void
    {
        Http::fake([
            'api.brex.com/v2/accounts/cash*' => Http::response(['items' => [], 'next_cursor' => null]),
        ]);

        $this->assertTrue($this->handler(['api_token' => self::TOKEN])->setup());

        $this->assertSame(self::TOKEN, (string) $this->company->get(ConfigurationEnum::API_TOKEN->value));
        $this->assertSame((string) $this->kanvasApp->getId(), (string) $this->company->get(ConfigurationEnum::SYNC_APP_ID->value));
    }

    public function testARejectedTokenFailsSetupAndKeepsTheTokenThatWorked(): void
    {
        Http::fake([
            'api.brex.com/*' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        try {
            $this->handler(['api_token' => 'typo-token'])->setup();
            $this->fail('A token Brex rejects must fail setup.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Brex authentication failed', $e->getMessage());
            $this->assertStringNotContainsString('typo-token', $e->getMessage());
        }

        $this->assertSame(self::TOKEN, (string) $this->company->get(ConfigurationEnum::API_TOKEN->value));
    }

    public function testCashSignAndCardSignBothMapMoneyOutToDebit(): void
    {
        $cashOut = BrexTransaction::fromCashApi($this->cashTransactionPayload('c1', -12_345, 'PAYMENT'));
        $cashIn = BrexTransaction::fromCashApi($this->cashTransactionPayload('c2', 50_000, 'PAYMENT'));
        $purchase = BrexTransaction::fromCardApi($this->cardTransactionPayload('k1', 4_250, 'PURCHASE'));
        $refund = BrexTransaction::fromCardApi($this->cardTransactionPayload('k2', -1_000, 'REFUND'));

        $this->assertSame(BankTransactionDirectionEnum::DEBIT, $cashOut->direction);
        $this->assertSame(123.45, $cashOut->amount, 'Brex sends cents.');
        $this->assertSame(BankTransactionDirectionEnum::CREDIT, $cashIn->direction);
        $this->assertSame(BankTransactionDirectionEnum::DEBIT, $purchase->direction);
        $this->assertSame(42.50, $purchase->amount);
        $this->assertSame('Test Merchant', $purchase->counterpartyName);
        $this->assertSame(BankTransactionDirectionEnum::CREDIT, $refund->direction);
    }

    public function testOnlyTypesWeCanNameGetACategory(): void
    {
        $this->assertSame(BankTransactionCategoryEnum::BANK_FEE, BrexTransaction::fromCashApi($this->cashTransactionPayload('f', -500, 'FEE'))->category);
        $this->assertSame(BankTransactionCategoryEnum::INTEREST_INCOME, BrexTransaction::fromCashApi($this->cashTransactionPayload('i', 700, 'INTEREST'))->category);
        $this->assertSame(BankTransactionCategoryEnum::TRANSFER, BrexTransaction::fromCashApi($this->cashTransactionPayload('t', -9_000, 'CARD_COLLECTION'))->category);
        $this->assertSame(BankTransactionCategoryEnum::TRANSFER, BrexTransaction::fromCardApi($this->cardTransactionPayload('t2', -9_000, 'COLLECTION'))->category);
        // A payment is exactly what should settle a bill or an invoice: the matcher decides, not the feed.
        $this->assertSame(BankTransactionCategoryEnum::UNKNOWN, BrexTransaction::fromCashApi($this->cashTransactionPayload('p', 9_000, 'PAYMENT'))->category);
    }

    public function testPullingAccountsCreatesCashAndOneCardAccountIdempotently(): void
    {
        Http::fake([
            'api.brex.com/v2/accounts/cash*' => Http::response([
                'items' => [$this->cashAccountPayload($this->cashAccountId, 1_234_567)],
                'next_cursor' => null,
            ]),
            'api.brex.com/v2/accounts/card*' => Http::response([
                ['id' => 'card_acct_1', 'current_balance' => ['amount' => 20_000, 'currency' => 'USD'], 'current_statement_period' => []],
            ]),
        ]);

        $first = new PullBrexAccountsAction($this->kanvasApp, $this->company, static::$cachedUser)->execute();
        $second = new PullBrexAccountsAction($this->kanvasApp, $this->company, static::$cachedUser)->execute();

        $this->assertCount(2, $first);
        $this->assertSame(
            array_map(fn (BankAccount $account): int => $account->getId(), $first),
            array_map(fn (BankAccount $account): int => $account->getId(), $second),
            'A second pull refreshes the same rows.'
        );

        $cash = $first[0];
        $this->assertSame('brex', $cash->source);
        $this->assertSame($this->cashAccountId, $cash->external_id);
        $this->assertSame(12_345.67, round((float) $cash->current_balance_native, 2));
        $this->assertSame('0001', $cash->account_number_last4);
        $this->assertSame($this->accountIdBySubType(AccountSubTypeEnum::CASH_CHECKING), $cash->gl_account_id);

        $card = $first[1];
        $this->assertSame(BrexAccount::CARD_EXTERNAL_ID, $card->external_id);
        $this->assertSame($this->accountIdBySubType(AccountSubTypeEnum::CREDIT_CARD_LIABILITY), $card->gl_account_id);
    }

    public function testPullingCashTransactionsLandsThemOnceAndSkipsWhatItCannotBook(): void
    {
        $bankAccount = $this->cashBankAccount();

        Http::fake([
            'api.brex.com/v2/transactions/cash/' . $this->cashAccountId . '*' => Http::response([
                'items' => [
                    $this->cashTransactionPayload($this->cashAccountId . '-out', -25_000, 'PAYMENT'),
                    $this->cashTransactionPayload($this->cashAccountId . '-in', 100_000, 'PAYMENT'),
                    $this->cashTransactionPayload(
                        $this->cashAccountId . '-eur',
                        5_000,
                        'PAYMENT',
                        'EUR'
                    ),
                    ['id' => $this->cashAccountId . '-noamount', 'description' => 'Pending', 'initiated_at_date' => '2026-06-15', 'posted_at_date' => '2026-06-15'],
                ],
                'next_cursor' => null,
            ]),
        ]);

        $landed = new PullBrexTransactionsAction($bankAccount, static::$cachedUser)->execute();
        new PullBrexTransactionsAction($bankAccount, static::$cachedUser)->execute();

        $this->assertCount(2, $landed);

        $rows = BankTransaction::query()
            ->where('bank_account_id', $bankAccount->getId())
            ->orderBy('external_id')
            ->get();

        $this->assertCount(2, $rows, 'Re-pulling the overlap window must not duplicate.');

        $in = $rows->firstWhere('external_id', $this->cashAccountId . '-in');
        $out = $rows->firstWhere('external_id', $this->cashAccountId . '-out');

        $this->assertSame(BankTransactionDirectionEnum::CREDIT, $in->direction);
        $this->assertSame(1_000.00, round((float) $in->amount_native, 2));
        $this->assertSame(BankTransactionDirectionEnum::DEBIT, $out->direction);
        $this->assertSame(250.00, round((float) $out->amount_native, 2));
        $this->assertSame('brex', $out->source);

        Http::assertSent(fn (Request $request): bool => isset($request->data()['posted_at_start'])
            && str_ends_with((string) $request->data()['posted_at_start'], 'Z'));
    }

    public function testPullingCardTransactionsUsesTheCardFeed(): void
    {
        $cardAccount = $this->bankAccountFor(BrexAccount::CARD_EXTERNAL_ID, AccountSubTypeEnum::CREDIT_CARD_LIABILITY);
        $id = 'card_tx_' . bin2hex(random_bytes(6));

        Http::fake([
            'api.brex.com/v2/transactions/card/primary*' => Http::response([
                'items' => [$this->cardTransactionPayload($id, 4_250, 'PURCHASE')],
                'next_cursor' => null,
            ]),
        ]);

        $landed = new PullBrexTransactionsAction($cardAccount, static::$cachedUser)->execute();

        $this->assertCount(1, $landed);
        $this->assertSame(BankTransactionDirectionEnum::DEBIT, $landed[0]->direction);
        $this->assertSame('Test Merchant', $landed[0]->counterparty_name);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'transactions/cash'));
    }

    public function testAPullRefusesABankAccountThatIsNotBrex(): void
    {
        $account = $this->cashBankAccount();
        $account->source = 'mercury';

        Http::fake();

        $this->expectExceptionMessageMatches('/not a Brex account/');

        new PullBrexTransactionsAction($account)->execute();
    }

    private function handler(array $data): BrexHandler
    {
        $region = Regions::firstWhere('apps_id', $this->kanvasApp->getId()) ?? Regions::first();

        return new BrexHandler(
            $this->kanvasApp,
            $this->company,
            $region,
            $data
        );
    }

    private function cashBankAccount(): BankAccount
    {
        return $this->bankAccountFor($this->cashAccountId, AccountSubTypeEnum::CASH_CHECKING);
    }

    private function bankAccountFor(string $externalId, AccountSubTypeEnum $subType): BankAccount
    {
        return new CreateBankAccountAction(
            data: new BankAccountData(
                app: $this->kanvasApp,
                company: $this->company,
                account_name: 'Brex ' . $externalId,
                gl_account_id: $this->accountIdBySubType($subType),
                currency: 'USD',
                source: 'brex',
                external_id: $externalId,
            ),
            user: static::$cachedUser,
        )->execute();
    }

    private function cashAccountPayload(string $id, int $balanceCents = 100_000): array
    {
        return [
            'id' => $id,
            'name' => 'Test Brex Cash',
            'status' => 'ACTIVE',
            'current_balance' => ['amount' => $balanceCents, 'currency' => 'USD'],
            'available_balance' => ['amount' => $balanceCents, 'currency' => 'USD'],
            'account_number' => '000000000001',
            'routing_number' => '000000000',
            'primary' => true,
        ];
    }

    private function cashTransactionPayload(
        string $id,
        int $signedCents,
        string $type,
        string $currency = 'USD'
    ): array {
        return [
            'id' => $id,
            'description' => 'Test ' . $type,
            'amount' => ['amount' => $signedCents, 'currency' => $currency],
            'initiated_at_date' => '2026-06-14',
            'posted_at_date' => '2026-06-15',
            'type' => $type,
            'transfer_id' => null,
        ];
    }

    private function cardTransactionPayload(string $id, int $signedCents, string $type): array
    {
        return [
            'id' => $id,
            'card_id' => 'card_1',
            'description' => 'Test card ' . $type,
            'amount' => ['amount' => $signedCents, 'currency' => 'USD'],
            'initiated_at_date' => '2026-06-14',
            'posted_at_date' => '2026-06-15',
            'type' => $type,
            'merchant' => ['raw_descriptor' => 'Test Merchant', 'mcc' => '5734', 'country' => 'USA'],
        ];
    }
}
