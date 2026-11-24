<?php

declare(strict_types=1);

namespace Tests\Scribe\Banking;

use Illuminate\Support\Carbon;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Scribe\Banking\Actions\CreateBankAccountAction;
use Kanvas\Scribe\Banking\DataTransferObject\BankAccount as BankAccountData;
use Kanvas\Scribe\Banking\DataTransferObject\BankStatementLine;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Services\BankStatementFileReaderService;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\Actions\ReceiveBillAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\Ledger\Models\JournalEntryLine;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

/**
 * Shared setup for the statement / payment tests. ScribeTestCase seeds the chart of accounts and freezes
 * the clock inside the open June 2026 fiscal period.
 */
abstract class BankStatementTestCase extends ScribeTestCase
{
    protected function statementAccount(
        string $last4 = '4321',
        string $currency = 'USD',
        string $name = 'Popular Checking',
    ): BankAccount {
        return new CreateBankAccountAction(
            data: new BankAccountData(
                app: $this->kanvasApp,
                company: $this->company,
                account_name: $name . ' ' . uniqid(),
                gl_account_id: $this->accountIdBySubType(AccountSubTypeEnum::CASH_CHECKING),
                currency: $currency,
                account_number_last4: $last4,
                institution_name: 'Banco Popular',
                source: 'kanvas',
            ),
            user: static::$cachedUser,
        )->execute();
    }

    protected function line(
        string $date,
        float $amount,
        ?float $running = null,
        string $description = 'Movement',
        ?string $reference = null
    ): BankStatementLine {
        return new BankStatementLine(
            date: Carbon::parse($date),
            amount: $amount,
            description: $description,
            reference: $reference,
            runningBalance: $running,
        );
    }

    protected function receivedBill(string $vendorName, float $amount): Bill
    {
        $vendor = $this->seedTestOrganization($vendorName);

        $draft = new CreateBillAction(
            data: new BillData(
                app: $this->kanvasApp,
                company: $this->company,
                vendor: $vendor,
                lines: new DataCollection(BillLineData::class, [
                    new BillLineData(
                        description: 'Service',
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
     * Makes BankStatementFileReaderService return $contents for any file, so no storage disk is needed.
     */
    protected function fakeStatementFileContents(string $contents): void
    {
        $this->app->instance(BankStatementFileReaderService::class, new class ($contents) extends BankStatementFileReaderService {
            public function __construct(private readonly string $contents)
            {
            }

            public function read(Filesystem $file): string
            {
                return $this->contents;
            }
        });
    }

    protected function netMovementOn(AccountSubTypeEnum $subType): float
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
