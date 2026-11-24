<?php

declare(strict_types=1);

namespace App\Console\Commands\Setup;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\Setup\Actions\SeedLedgerAction;
use Kanvas\Users\Models\Users;

class ScribeSetupCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-scribe:setup
        {app_id}
        {user_id}
        {company_id}
        {--country=US : ISO 3166-1 alpha-2 country code (US, DO, …) — selects COA template}
        {--fiscal-year= : First calendar year to open monthly fiscal periods for; the next year is opened too (default: current year)}';

    protected $description = 'Initialize Scribe accounting for a company — seed Chart of Accounts (country-aware) and pre-open the monthly fiscal periods for the year and the next.';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $user = Users::getById((int) $this->argument('user_id'));
        $company = Companies::getById((int) $this->argument('company_id'));
        $country = strtoupper((string) $this->option('country'));
        $fiscalYear = (int) ($this->option('fiscal-year') ?? Carbon::today()->year);

        $this->overwriteAppService($app);

        $result = new SeedLedgerAction(
            app: $app,
            company: $company,
            user: $user,
            countryCode: $country,
            fiscalYearStart: Carbon::create($fiscalYear, 1, 1),
        )->execute();
        $inserted = $result['accounts_inserted'];
        $opened = $result['months_opened'];

        $this->info("Chart of Accounts seeded for country={$country}: {$inserted} accounts inserted.");

        $this->info("Fiscal years {$fiscalYear}-" . ($fiscalYear + 1) . ": {$opened} months opened.");

        $this->newLine();
        $this->info("Scribe setup complete for {$company->name} (app={$app->name}, country={$country}, fiscal year={$fiscalYear}).");

        return self::SUCCESS;
    }
}
