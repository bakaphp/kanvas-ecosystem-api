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

    protected $signature = 'kanvas-scribe:setup {app_id} {user_id} {company_id} {--country=US : ISO 3166-1 alpha-2 country code (US, DO, …)}';

    protected $description = 'Initialize Scribe accounting for a company — seed Chart of Accounts (country-aware) and open the current fiscal period.';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $user = Users::getById((int) $this->argument('user_id'));
        $company = Companies::getById((int) $this->argument('company_id'));
        $country = strtoupper((string) $this->option('country'));

        $this->overwriteAppService($app);

        $inserted = new ChartOfAccountsSeederService()->seedForCountry(
            appsId: $app->getId(),
            companiesId: $company->getId(),
            countryCode: $country,
            userId: $user->getId(),
        );
        $this->info("Chart of Accounts seeded for country={$country}: {$inserted} accounts inserted.");

        $today = Carbon::today();
        $period = FiscalPeriod::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('period_start', '<=', $today)
            ->where('period_end', '>=', $today)
            ->first();
        if ($period === null) {
            $period = FiscalPeriod::create([
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'period_start' => $today->copy()->startOfMonth(),
                'period_end' => $today->copy()->endOfMonth(),
                'status' => FiscalPeriodStatusEnum::OPEN,
            ]);
            $this->info("Fiscal period opened: {$period->period_start->toDateString()} → {$period->period_end->toDateString()}.");
        } else {
            $this->info("Fiscal period already exists (status={$period->status->value}); skipping.");
        }

        $this->newLine();
        $this->info("Scribe setup complete for {$company->name} (app={$app->name}, country={$country}).");

        return self::SUCCESS;
    }
}
