<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Setup\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\Ledger\Services\ChartOfAccountsSeederService;
use Kanvas\Scribe\Ledger\Services\FiscalPeriodAutoOpenService;

/**
 * The chart of accounts for the company's country and the rolling window of open months. Idempotent:
 * accounts that exist are skipped, months that have a period are left alone.
 */
class SeedLedgerAction
{
    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
        private readonly UserInterface $user,
        private readonly string $countryCode,
        private readonly ?Carbon $fiscalYearStart = null,
    ) {
    }

    /**
     * @return array{accounts_inserted: int, months_opened: int}
     */
    public function execute(): array
    {
        return [
            'accounts_inserted' => $this->seedChartOfAccounts(),
            'months_opened' => $this->openPeriods(),
        ];
    }

    public function seedChartOfAccounts(): int
    {
        return new ChartOfAccountsSeederService()->seedForCountry(
            appsId: $this->app->getId(),
            companiesId: $this->company->getId(),
            countryCode: strtoupper($this->countryCode),
            userId: $this->user->getId(),
        );
    }

    public function openPeriods(): int
    {
        return new FiscalPeriodAutoOpenService()->openRollingWindow(
            $this->app,
            $this->company,
            $this->user,
            $this->fiscalYearStart,
        );
    }
}
