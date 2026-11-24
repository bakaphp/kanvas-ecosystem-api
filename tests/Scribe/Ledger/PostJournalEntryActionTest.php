<?php

declare(strict_types=1);

namespace Tests\Scribe\Ledger;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\Ledger\Actions\PostJournalEntryAction;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntry as JournalEntryData;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntryLine as JournalEntryLineData;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Enums\FiscalPeriodStatusEnum;
use Kanvas\Scribe\Ledger\Exceptions\ClosedFiscalPeriodException;
use Kanvas\Scribe\Ledger\Exceptions\UnbalancedJournalEntryException;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\FiscalPeriod;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\Ledger\Models\JournalEntryLine;
use Kanvas\Scribe\Ledger\Services\ChartOfAccountsSeederService;
use Kanvas\Scribe\Ledger\Services\FiscalPeriodAutoOpenService;
use Kanvas\Scribe\Ledger\Services\PeriodCloseService;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

/**
 * End-to-end test of PostJournalEntryAction.
 *
 * Verifies:
 *   - balanced JE writes header + lines in a single transaction
 *   - posting to a hard-closed period throws
 *   - posting to a date with no fiscal_period row opens it inside the rolling window and throws outside it
 *   - unbalanced JE throws before any DB write
 *
 * @see plan §3.5 sub-ledger → GL auto-posting
 * @see plan §7.7 GL invariants
 */
class PostJournalEntryActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'accounting'];

    private Apps $kanvasApp;
    private Companies $company;
    private int $arAccountId;
    private int $revenueAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();

        $seeder = new ChartOfAccountsSeederService();
        $seeder->seedUsDefault($this->kanvasApp->getId(), $this->company->getId());

        $this->arAccountId = $this->accountIdBySubType(AccountSubTypeEnum::ACCOUNTS_RECEIVABLE);
        $this->revenueAccountId = $this->accountIdBySubType(AccountSubTypeEnum::SERVICE_REVENUE);

        FiscalPeriod::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'status' => FiscalPeriodStatusEnum::OPEN,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_balanced_invoice_je_writes_header_and_lines(): void
    {
        $action = new PostJournalEntryAction(
            data: $this->makeJeData(
                postedAt: Carbon::parse('2026-06-15 10:00:00'),
                sourceType: 'invoice',
                sourceId: 42,
                memo: 'Test invoice JE',
                lines: [
                    $this->line($this->arAccountId, debit: 1180.00),
                    $this->line($this->revenueAccountId, credit: 1180.00),
                ],
            ),
        );

        $je = $action->execute();

        $this->assertSame('posted', $je->status->value);
        $this->assertCount(2, $je->lines, 'JE should have 2 lines after posting.');

        $totalDebit = $je->lines->sum('debit_base');
        $totalCredit = $je->lines->sum('credit_base');
        $this->assertEquals($totalDebit, $totalCredit, 'Debit base sum equals credit base sum.');

        $this->assertNotNull($je->fiscal_period_id);
    }

    public function test_unbalanced_je_throws_and_writes_nothing(): void
    {
        $action = new PostJournalEntryAction(
            data: $this->makeJeData(
                postedAt: Carbon::parse('2026-06-15'),
                sourceType: 'manual',
                lines: [
                    $this->line($this->arAccountId, debit: 1000),
                    $this->line($this->revenueAccountId, credit: 999),
                ],
            ),
        );

        $jeCountBefore = JournalEntry::query()->where('apps_id', $this->kanvasApp->getId())->count();
        $lineCountBefore = JournalEntryLine::query()->whereIn(
            'journal_entry_id',
            JournalEntry::query()->where('apps_id', $this->kanvasApp->getId())->pluck('id')
        )->count();

        try {
            $action->execute();
            $this->fail('Expected UnbalancedJournalEntryException, none thrown.');
        } catch (UnbalancedJournalEntryException) {
            // expected
        }

        $jeCountAfter = JournalEntry::query()->where('apps_id', $this->kanvasApp->getId())->count();
        $lineCountAfter = JournalEntryLine::query()->whereIn(
            'journal_entry_id',
            JournalEntry::query()->where('apps_id', $this->kanvasApp->getId())->pluck('id')
        )->count();

        $this->assertSame($jeCountBefore, $jeCountAfter, 'No JE header should have been written on validator failure.');
        $this->assertSame($lineCountBefore, $lineCountAfter, 'No JE lines should have been written.');
    }

    public function test_posting_to_hard_closed_period_throws(): void
    {
        FiscalPeriod::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'status' => FiscalPeriodStatusEnum::HARD_CLOSED,
            'closed_at' => Carbon::now(),
        ]);

        $action = new PostJournalEntryAction(
            data: $this->makeJeData(
                postedAt: Carbon::parse('2026-05-15'),
                sourceType: 'manual',
                lines: [
                    $this->line($this->arAccountId, debit: 100),
                    $this->line($this->revenueAccountId, credit: 100),
                ],
            ),
        );

        $this->expectException(ClosedFiscalPeriodException::class);
        $action->execute();
    }

    public function test_posting_to_date_with_no_period_outside_the_rolling_window_throws(): void
    {
        Carbon::setTestNow('2026-06-10');

        $action = new PostJournalEntryAction(
            data: $this->makeJeData(
                postedAt: Carbon::parse('2031-01-15'),    // no period, and past the current + next year
                sourceType: 'manual',
                lines: [
                    $this->line($this->arAccountId, debit: 100),
                    $this->line($this->revenueAccountId, credit: 100),
                ],
            ),
        );

        $this->expectException(ClosedFiscalPeriodException::class);
        $action->execute();
    }

    public function test_posting_in_january_opens_the_month_instead_of_failing(): void
    {
        Carbon::setTestNow('2026-12-28');

        $entry = new PostJournalEntryAction(
            data: $this->makeJeData(
                postedAt: Carbon::parse('2027-01-04'),
                sourceType: 'manual',
                lines: [
                    $this->line($this->arAccountId, debit: 100),
                    $this->line($this->revenueAccountId, credit: 100),
                ],
            ),
        )->execute();

        $period = FiscalPeriod::query()->find($entry->fiscal_period_id);
        $this->assertSame('2027-01-01', $period->period_start->toDateString());
        $this->assertSame('2027-01-31', $period->period_end->toDateString());
        $this->assertSame(FiscalPeriodStatusEnum::OPEN, $period->status);
    }

    public function test_auto_open_never_reopens_a_closed_month(): void
    {
        Carbon::setTestNow('2026-06-20');
        FiscalPeriod::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->whereDate('period_start', '2026-06-01')
            ->update(['status' => FiscalPeriodStatusEnum::HARD_CLOSED->value]);

        $this->expectException(ClosedFiscalPeriodException::class);

        new PostJournalEntryAction(
            data: $this->makeJeData(
                postedAt: Carbon::parse('2026-06-15'),
                sourceType: 'manual',
                lines: [
                    $this->line($this->arAccountId, debit: 100),
                    $this->line($this->revenueAccountId, credit: 100),
                ],
            ),
        )->execute();
    }

    public function test_the_rolling_window_opens_every_month_of_this_year_and_the_next(): void
    {
        new FiscalPeriodAutoOpenService()->openRollingWindow(
            $this->kanvasApp,
            $this->company,
            today: Carbon::parse('2026-03-01'),
        );

        $periods = new PeriodCloseService();
        for ($month = Carbon::parse('2026-01-15'); $month->lte(Carbon::parse('2027-12-15')); $month->addMonthNoOverflow()) {
            $this->assertNotNull(
                $periods->findPeriodFor($this->kanvasApp->getId(), $this->company->getId(), $month),
                "No fiscal period covers {$month->toDateString()}."
            );
        }
        $this->assertNull($periods->findPeriodFor($this->kanvasApp->getId(), $this->company->getId(), Carbon::parse('2028-01-15')));
    }

    /**
     * @param array<int, JournalEntryLineData> $lines
     */
    private function makeJeData(
        Carbon $postedAt,
        string $sourceType,
        array $lines,
        ?int $sourceId = null,
        ?string $memo = null,
    ): JournalEntryData {
        return new JournalEntryData(
            app: $this->kanvasApp,
            company: $this->company,
            postedAt: $postedAt,
            sourceType: $sourceType,
            lines: new DataCollection(JournalEntryLineData::class, $lines),
            sourceId: $sourceId,
            memo: $memo,
        );
    }

    private function line(int $accountId, float $debit = 0, float $credit = 0): JournalEntryLineData
    {
        return new JournalEntryLineData(
            account_id: $accountId,
            debit_native: $debit,
            credit_native: $credit,
            debit_base: $debit,
            credit_base: $credit,
            currency: 'USD',
            fx_rate_to_base: 1.0,
        );
    }

    private function accountIdBySubType(AccountSubTypeEnum $subType): int
    {
        $row = Account::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('account_sub_type', $subType->value)
            ->first();

        $this->assertNotNull($row, "Expected seeded account with sub_type='{$subType->value}'.");

        return (int) $row->id;
    }
}
