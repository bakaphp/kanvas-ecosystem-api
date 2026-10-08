<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\VinSolution;

use App\Console\Commands\Connectors\Concerns\CreatesDealerUsers;
use App\Console\Commands\Connectors\VinSolution\Concerns\InteractsWithVinSolutionCompanies;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Throwable;

class SyncUsersCommand extends Command
{
    use CreatesDealerUsers;
    use InteractsWithVinSolutionCompanies;
    use KanvasJobsTrait;

    protected $signature = 'kanvas:vinsolution-sync-users
                            {app_id : The application ID}
                            {company_ids? : Comma-separated company IDs. Omit to auto-discover every VinSolution-configured company for the app}
                            {--create-missing=1 : Create Kanvas users for VinSolution users with no matching email (1=yes, 0=map only)}
                            {--password= : Password for created users, required when --create-missing=1}';

    protected $description = 'Sync VinSolution dealer users into Kanvas: match by email and store the VinSolution user id, creating missing users with the given --password.';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        if ($this->newUserPasswordIsMissing()) {
            return self::FAILURE;
        }

        $companyIds = $this->argument('company_ids');
        $companies = $this->resolveVinCompanies($app, is_string($companyIds) ? $companyIds : null);

        if ($companies->isEmpty()) {
            $this->info('No VinSolution-configured companies to process.');

            return self::SUCCESS;
        }

        foreach ($companies as $company) {
            $this->processCompany($app, $company);
            $this->newLine();
        }

        $this->info('=== VinSolution user sync completed ===');

        return self::SUCCESS;
    }

    private function processCompany(Apps $app, Companies $company): void
    {
        $dealerId = $company->get(ConfigurationEnum::COMPANY->value);

        if (! $dealerId) {
            $this->error("Company {$company->getId()} does not have VinSolution configuration");

            return;
        }

        $this->info("=== Company {$company->getId()} (dealer {$dealerId}) ===");

        try {
            $dealer = Dealer::getById((int) $dealerId, $app);
            $vinUsers = Dealer::getUsers($dealer, $app);
        } catch (Throwable $e) {
            $this->error('Failed to fetch VinSolution users: ' . $e->getMessage());

            return;
        }

        $matched = 0;
        $created = 0;
        $skipped = 0;

        foreach ($vinUsers as $vinUser) {
            if (empty($vinUser->email)) {
                $skipped++;

                continue;
            }

            $user = $this->findOrCreateDealerUser(
                $app,
                $company,
                $vinUser->email,
                (string) $vinUser->firstName,
                (string) $vinUser->lastName
            );

            if ($user === null) {
                $skipped++;

                continue;
            }

            if ($user->wasRecentlyCreated) {
                $created++;
            }

            $user->set(
                ConfigurationEnum::getUserKey($company, $user),
                $vinUser->id
            );
            $matched++;
        }

        $this->info("Mapped: {$matched} | Created: {$created} | Skipped: {$skipped}");
    }
}
