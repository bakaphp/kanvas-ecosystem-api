<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Elead;

use App\Console\Commands\Connectors\Concerns\CreatesDealerUsers;
use App\Console\Commands\Connectors\Concerns\ResolvesConfiguredCompanies;
use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Entities\Employee;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Throwable;

class SyncUsersCommand extends Command
{
    use CreatesDealerUsers;
    use KanvasJobsTrait;
    use ResolvesConfiguredCompanies;

    protected $signature = 'kanvas:elead-sync-users
                            {app_id : The application ID}
                            {company_ids? : Comma-separated company IDs. Omit to auto-discover every eLeads-configured company for the app}
                            {--create-missing=1 : Create Kanvas users for eLeads employees with no matching email (1=yes, 0=map only)}
                            {--password= : Password for created users, required when --create-missing=1}';

    protected $description = 'Sync eLeads dealer employees into Kanvas: match by email and store the eLeads employee id so downloaded leads get an owner, creating missing users with the given --password.';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        if ($this->newUserPasswordIsMissing()) {
            return self::FAILURE;
        }

        $companyIds = $this->argument('company_ids');
        $companies = $this->resolveConfiguredCompanies(
            $app,
            is_string($companyIds) ? $companyIds : null,
            CustomFieldEnum::COMPANY->value
        );

        foreach ($companies as $company) {
            $this->processCompany($app, $company);
            $this->newLine();
        }

        $this->info('=== eLeads user sync completed ===');

        return self::SUCCESS;
    }

    private function processCompany(Apps $app, Companies $company): void
    {
        if (! $company->get(CustomFieldEnum::COMPANY->value)) {
            $this->error("Company {$company->getId()} does not have eLeads configuration");

            return;
        }

        $this->info("=== Company {$company->getId()} ({$company->name}) ===");

        $userKey = CustomFieldEnum::getUserKey($company);
        $seen = [];
        $matched = 0;
        $created = 0;
        $skipped = 0;

        foreach (Employee::POSITIONS as $position) {
            try {
                $employees = iterator_to_array(
                    Employee::getAll(
                        $app,
                        $company,
                        $position,
                        fresh: true
                    ),
                    false
                );
            } catch (Throwable $e) {
                $this->warn("Failed to fetch {$position} employees: " . $e->getMessage());

                continue;
            }

            foreach ($employees as $employee) {
                if ($employee->id === null || isset($seen[$employee->id]) || ! $employee->isActive) {
                    continue;
                }
                $seen[$employee->id] = true;

                try {
                    $email = strtolower((string) ($employee->getEmails()[0]['address'] ?? ''));
                } catch (Throwable) {
                    $email = '';
                }

                $generatedEmail = $email === '';
                if ($generatedEmail) {
                    $email = $this->generateEmployeeEmail($company, $employee);
                }

                if ($email === null) {
                    $this->warn("Employee {$employee->id} has no email and no name to generate one — skipped");
                    $skipped++;

                    continue;
                }

                $user = $this->findOrCreateDealerUser(
                    $app,
                    $company,
                    $email,
                    (string) $employee->firstName,
                    (string) $employee->lastName
                );

                if ($user === null) {
                    $skipped++;

                    continue;
                }

                // Two employees with the same name at one dealer generate the same address.
                $mappedEmployeeId = $user->get($userKey);
                if ($generatedEmail && $mappedEmployeeId && (string) $mappedEmployeeId !== (string) $employee->id) {
                    $this->warn("{$email} is already mapped to eLeads employee {$mappedEmployeeId}, employee {$employee->id} skipped");
                    $skipped++;

                    continue;
                }

                if ($user->wasRecentlyCreated) {
                    $created++;
                }

                $user->set($userKey, $employee->id);
                $matched++;
            }
        }

        $this->info("Mapped: {$matched} | Created: {$created} | Skipped: {$skipped}");
    }

    protected function generateEmployeeEmail(Companies $company, Employee $employee): ?string
    {
        $nameSlug = Str::slug($employee->firstName . $employee->lastName, '');
        $companySlug = Str::slug((string) $company->name, '');
        if ($nameSlug === '' || $companySlug === '') {
            return null;
        }

        return strtolower($nameSlug . $companySlug . '@' . $this->companyEmailDomain($company, $companySlug));
    }

    /**
     * The website is free text ("acme.com", "https://www.acme.com/"), so only its host is kept.
     */
    private function companyEmailDomain(Companies $company, string $companySlug): string
    {
        $website = Str::trimToNull((string) $company->website);
        if ($website !== null) {
            $host = parse_url(str_contains($website, '://') ? $website : 'https://' . $website, PHP_URL_HOST);
            if (is_string($host) && str_contains($host, '.')) {
                return preg_replace('/^www\./i', '', $host);
            }
        }

        return $companySlug . '.io';
    }
}
