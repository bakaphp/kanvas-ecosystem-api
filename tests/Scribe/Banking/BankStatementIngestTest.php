<?php

declare(strict_types=1);

namespace Tests\Scribe\Banking;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Banking\Actions\IngestBankStatementAction;
use Kanvas\Scribe\Banking\DataTransferObject\BankStatementIngestResult;
use Kanvas\Scribe\Banking\DataTransferObject\ParsedBankStatement;
use Kanvas\Scribe\Banking\Enums\BankStatementIngestOutcomeEnum;
use Kanvas\Scribe\Banking\Enums\BankStatementRejectionEnum;
use Kanvas\Scribe\Banking\Enums\BankStatementSourceEnum;
use Kanvas\Scribe\Banking\Enums\BankStatementStatusEnum;
use Kanvas\Scribe\Banking\Enums\BankTransactionMatchOutcomeEnum;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Models\BankStatement;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\Scribe\Banking\Services\BankStatementCoverageService;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;

final class BankStatementIngestTest extends BankStatementTestCase
{
    private BankAccount $account;

    protected function afterScribeSetUp(): void
    {
        $this->account = $this->statementAccount('4321', 'USD');
    }

    public function testABalancedStatementLandsEveryRowAndRunsTheMatcher(): void
    {
        $bill = $this->receivedBill('Claro Dominicana', 300.00);

        $result = $this->ingest(new ParsedBankStatement(
            lines: [
                $this->line(
                    '2026-06-02',
                    1_000.00,
                    6_000.00,
                    'Deposit'
                ),
                $this->line(
                    '2026-06-05',
                    -300.00,
                    5_700.00,
                    'Pago Claro Dominicana'
                ),
                $this->line(
                    '2026-06-06',
                    -25.00,
                    5_675.00,
                    'Comision mantenimiento'
                ),
            ],
            accountNumber: '****4321',
            currency: 'USD',
            openingBalance: 5_000.00,
            closingBalance: 5_675.00,
        ));

        $this->assertSame(BankStatementIngestOutcomeEnum::POSTED, $result->outcome);
        $this->assertSame(3, $result->newRows);
        $this->assertSame(BankStatementStatusEnum::POSTED, $result->statement->status);
        $this->assertSame('2026-06-02', $result->statement->period_start->toDateString());
        $this->assertSame('2026-06-06', $result->statement->period_end->toDateString());
        $this->assertSame(3, $result->statement->transactions()->count());
        $this->assertSame(
            BankStatement::TRANSACTION_SOURCE,
            $result->statement->transactions()->first()->source,
        );

        $this->assertSame(BillDocumentStatusEnum::PAID, $bill->refresh()->document_status, 'The matcher settled the bill the statement paid.');
        $this->assertSame(1, $result->matchOutcomes[BankTransactionMatchOutcomeEnum::SETTLED->value] ?? 0);
    }

    public function testAStatementThatDoesNotBalanceLandsNothing(): void
    {
        $result = $this->ingest(new ParsedBankStatement(
            lines: [
                $this->line('2026-06-02', 1_000.00),
                $this->line('2026-06-05', -300.00),
            ],
            currency: 'USD',
            openingBalance: 5_000.00,
            closingBalance: 5_800.00,
        ));

        $this->assertSame(BankStatementIngestOutcomeEnum::REJECTED, $result->outcome);
        $this->assertSame(BankStatementRejectionEnum::BALANCE_MISMATCH, $result->reasonCode);
        $this->assertStringContainsString('difference 100.00', (string) $result->reason);
        $this->assertSame(BankStatementStatusEnum::REJECTED, $result->statement->status);
        $this->assertSame(0, $this->transactionsOnAccount());
    }

    public function testABrokenRunningBalanceLandsNothingAndNamesTheRow(): void
    {
        // Opening + movements does match the closing balance; only the misread running balance on row 2
        // gives the bad scan away.
        $result = $this->ingest(new ParsedBankStatement(
            lines: [
                $this->line('2026-06-02', 1_000.00, 6_000.00),
                $this->line('2026-06-05', -300.00, 5_800.00),
                $this->line('2026-06-06', -25.00, 5_675.00),
            ],
            openingBalance: 5_000.00,
            closingBalance: 5_675.00,
        ));

        $this->assertSame(BankStatementRejectionEnum::RUNNING_BALANCE_BREAK, $result->reasonCode);
        $this->assertStringContainsString('Row 2', (string) $result->reason);
        $this->assertSame(0, $this->transactionsOnAccount());
    }

    public function testANewestFirstExportIsAcceptedInChronologicalOrder(): void
    {
        $result = $this->ingest(new ParsedBankStatement(lines: [
            $this->line('2026-06-06', -25.00, 5_675.00),
            $this->line('2026-06-05', -300.00, 5_700.00),
            $this->line('2026-06-02', 1_000.00, 6_000.00),
        ]));

        $this->assertSame(BankStatementIngestOutcomeEnum::POSTED, $result->outcome);
        $this->assertSame(5_000.00, $result->statement->opening_balance, 'Opening derived from the oldest running balance.');
        $this->assertSame(5_675.00, $result->statement->closing_balance);
    }

    public function testAStatementWithNoBalancesAtAllIsRefused(): void
    {
        $result = $this->ingest(new ParsedBankStatement(lines: [$this->line('2026-06-02', 1_000.00)]));

        $this->assertSame(BankStatementRejectionEnum::NO_BALANCES, $result->reasonCode);
        $this->assertSame(0, $this->transactionsOnAccount());
    }

    public function testAnOverlappingStatementNeverDoublesAMovement(): void
    {
        $first = $this->ingest(new ParsedBankStatement(lines: [
            $this->line(
                '2026-06-02',
                1_000.00,
                6_000.00,
                'Deposit'
            ),
            $this->line(
                '2026-06-05',
                -25.00,
                5_975.00,
                'Fee'
            ),
            // Same day, same amount, same text and no reference: two real fees, kept apart by the running balance.
            $this->line(
                '2026-06-05',
                -25.00,
                5_950.00,
                'Fee'
            ),
        ]));

        $second = $this->ingest(new ParsedBankStatement(lines: [
            $this->line(
                '2026-06-02',
                1_000.00,
                6_000.00,
                'Deposit'
            ),
            $this->line(
                '2026-06-05',
                -25.00,
                5_975.00,
                'Fee'
            ),
            $this->line(
                '2026-06-05',
                -25.00,
                5_950.00,
                'Fee'
            ),
            $this->line(
                '2026-06-20',
                -50.00,
                5_900.00,
                'Transfer out',
                reference: 'TRX-991'
            ),
        ]));

        $this->assertSame(3, $first->newRows);
        $this->assertSame(BankStatementIngestOutcomeEnum::POSTED, $second->outcome);
        $this->assertSame(1, $second->newRows);
        $this->assertSame(3, $second->duplicateRows);
        $this->assertSame(4, $this->transactionsOnAccount());
        $this->assertSame(
            $first->statement->getId(),
            BankTransaction::query()->where('bank_account_id', $this->account->getId())->whereDate('transaction_date', '2026-06-02')->value('bank_statement_id'),
            'An overlap row stays credited to the statement that first delivered it.',
        );
    }

    public function testTheSameStatementTwiceIsADuplicate(): void
    {
        $statement = new ParsedBankStatement(lines: [$this->line('2026-06-02', 1_000.00, 6_000.00)]);

        $this->ingest($statement);
        $again = $this->ingest($statement);

        $this->assertSame(BankStatementIngestOutcomeEnum::DUPLICATE, $again->outcome);
        $this->assertSame(1, $this->transactionsOnAccount());
    }

    public function testAStatementForAnotherAccountNumberIsRefused(): void
    {
        $result = $this->ingest(new ParsedBankStatement(
            lines: [$this->line('2026-06-02', 1_000.00, 6_000.00)],
            accountNumber: '000-555-9999',
        ));

        $this->assertSame(BankStatementRejectionEnum::ACCOUNT_MISMATCH, $result->reasonCode);
        $this->assertSame(0, $this->transactionsOnAccount());
    }

    public function testAStatementInAnotherCurrencyIsRefused(): void
    {
        $result = $this->ingest(new ParsedBankStatement(
            lines: [$this->line('2026-06-02', 1_000.00, 6_000.00)],
            accountNumber: '4321',
            currency: 'DOP',
        ));

        $this->assertSame(BankStatementRejectionEnum::CURRENCY_MISMATCH, $result->reasonCode);
        $this->assertSame(0, $this->transactionsOnAccount());
    }

    public function testWithoutAnAccountTheHeaderLast4AndCurrencyPickIt(): void
    {
        $dop = $this->statementAccount('4321', 'DOP', 'Popular DOP');

        $result = new IngestBankStatementAction(
            app: $this->kanvasApp,
            company: $this->company,
            statement: new ParsedBankStatement(
                lines: [$this->line('2026-06-02', 1_000.00, 6_000.00)],
                accountNumber: '7890-4321',
                currency: 'DOP',
            ),
            source: BankStatementSourceEnum::PDF,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(BankStatementIngestOutcomeEnum::POSTED, $result->outcome);
        $this->assertSame($dop->getId(), $result->statement->bank_account_id);
    }

    public function testCoverageIsTheLatestPostedStatementEnd(): void
    {
        $coverage = new BankStatementCoverageService();
        $this->assertNull($coverage->coveredThrough($this->account));
        $this->assertTrue($coverage->isBehind($this->account));

        $this->ingest(new ParsedBankStatement(
            lines: [$this->line('2026-06-02', 1_000.00, 6_000.00)],
            periodStart: Carbon::parse('2026-05-25'),
            periodEnd: Carbon::parse('2026-06-01'),
        ));
        // A rejected statement never moves coverage forward.
        $this->ingest(new ParsedBankStatement(
            lines: [$this->line('2026-06-20', 1.00)],
            periodEnd: Carbon::parse('2026-06-30'),
        ));

        $this->assertSame('2026-06-01', $coverage->coveredThrough($this->account)->toDateString());
        $this->assertSame(0, $coverage->describe($this->account, Carbon::parse('2026-06-01'))['days_behind']);
        $this->assertTrue($coverage->isBehind($this->account, Carbon::parse('2026-06-20')));
        $this->assertFalse($coverage->isBehind($this->account, Carbon::parse('2026-06-05')));
    }

    public function testAMatchedStatementPostsCashOnce(): void
    {
        $this->ingest(new ParsedBankStatement(lines: [
            $this->line(
                '2026-06-06',
                -25.00,
                4_975.00,
                'Unknown debit'
            ),
        ]));

        $this->assertSame(-25.00, $this->netMovementOn(AccountSubTypeEnum::CASH_CHECKING));
        $this->assertSame(25.00, $this->netMovementOn(AccountSubTypeEnum::SUSPENSE));
    }

    private function ingest(ParsedBankStatement $statement): BankStatementIngestResult
    {
        return new IngestBankStatementAction(
            app: $this->kanvasApp,
            company: $this->company,
            statement: $statement,
            source: BankStatementSourceEnum::CSV,
            bankAccount: $this->account,
            user: static::$cachedUser,
        )->execute();
    }

    private function transactionsOnAccount(): int
    {
        return BankTransaction::query()->where('bank_account_id', $this->account->getId())->count();
    }
}
