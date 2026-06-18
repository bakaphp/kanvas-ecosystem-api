<?php

declare(strict_types=1);

namespace App\Console\Commands\Setup;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\Ledger\Enums\FiscalPeriodStatusEnum;
use Kanvas\Scribe\Ledger\Models\FiscalPeriod;
use Kanvas\Scribe\Ledger\Services\ChartOfAccountsSeederService;
use Kanvas\Users\Models\Users;

class ScribeSetupCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-scribe:setup
        {app_id}
        {user_id}
        {company_id}
        {--country=US : ISO 3166-1 alpha-2 country code (US, DO, …) — selects COA template}
        {--fiscal-year= : Calendar year to open monthly fiscal periods for (default: current year)}';

    protected $description = 'Initialize Scribe accounting for a company — seed Chart of Accounts (country-aware) and pre-open all 12 monthly fiscal periods for the year.';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $user = Users::getById((int) $this->argument('user_id'));
        $company = Companies::getById((int) $this->argument('company_id'));
        $country = strtoupper((string) $this->option('country'));
        $fiscalYear = (int) ($this->option('fiscal-year') ?? Carbon::today()->year);

        $this->overwriteAppService($app);

        $inserted = new ChartOfAccountsSeederService()->seedForCountry(
            appsId: $app->getId(),
            companiesId: $company->getId(),
            countryCode: $country,
            userId: $user->getId(),
        );
        $this->info("Chart of Accounts seeded for country={$country}: {$inserted} accounts inserted.");

        // Pre-open all 12 monthly periods for the fiscal year. Posting outside any open period
        // would otherwise throw ClosedFiscalPeriodException — operators shouldn't have to manually
        // open every month before recording transactions.
        $opened = 0;
        $skipped = 0;
        $cursor = Carbon::create($fiscalYear, 1, 1)->startOfMonth();
        $yearEnd = Carbon::create($fiscalYear, 12, 31);

        while ($cursor->lte($yearEnd)) {
            $monthStart = $cursor->copy()->startOfMonth();
            $monthEnd = $cursor->copy()->endOfMonth();

            $existing = FiscalPeriod::query()
                ->where('apps_id', $app->getId())
                ->where('companies_id', $company->getId())
                ->where('period_start', $monthStart->toDateString())
                ->where('period_end', $monthEnd->toDateString())
                ->first();

            if ($existing === null) {
                FiscalPeriod::create([
                    'apps_id' => $app->getId(),
                    'companies_id' => $company->getId(),
                    'period_start' => $monthStart,
                    'period_end' => $monthEnd,
                    'status' => FiscalPeriodStatusEnum::OPEN,
                ]);
                ++$opened;
            } else {
                ++$skipped;
            }

            $cursor->addMonthNoOverflow();
        }

        $this->info("Fiscal year {$fiscalYear}: {$opened} months opened, {$skipped} already existed.");

        $this->newLine();
        $this->info("Scribe setup complete for {$company->name} (app={$app->name}, country={$country}, fiscal year={$fiscalYear}).");

        return self::SUCCESS;
    }
}
