<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\VinSolution;

use App\Console\Commands\Connectors\VinSolution\Concerns\InteractsWithVinSolutionCompanies;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Leads\Types;
use Kanvas\Guild\Leads\Actions\CreateLeadTypeAction;
use Kanvas\Guild\Leads\DataTransferObject\LeadType as LeadTypeData;
use Kanvas\Guild\Leads\Models\LeadType;
use Throwable;

class SyncLeadTypesCommand extends Command
{
    use InteractsWithVinSolutionCompanies;
    use KanvasJobsTrait;

    protected $signature = 'kanvas:vinsolution-sync-lead-types
                            {app_id : The application ID}
                            {company_ids? : Comma-separated company IDs. Omit to auto-discover every VinSolution-configured company for the app}';

    protected $description = 'Download all VinSolution lead types and create the matching Kanvas lead types for the configured companies.';

    public function handle(): void
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $companyIds = $this->argument('company_ids');
        $companies = $this->resolveVinCompanies($app, is_string($companyIds) ? $companyIds : null);

        if ($companies->isEmpty()) {
            $this->info('No VinSolution-configured companies to process.');

            return;
        }

        try {
            // Lead types are a global VinSolution catalog, identical across dealers.
            $vinTypes = Types::getAll();
        } catch (Throwable $e) {
            $this->error('Failed to fetch VinSolution lead types: ' . $e->getMessage());

            return;
        }

        foreach ($companies as $company) {
            $this->processCompany($app, $company, $vinTypes);
            $this->newLine();
        }

        $this->info('=== VinSolution lead type sync completed ===');
    }

    private function processCompany(Apps $app, Companies $company, array $vinTypes): void
    {
        if (! $company->get(ConfigurationEnum::COMPANY->value)) {
            $this->error("Company {$company->getId()} does not have VinSolution configuration");

            return;
        }

        $this->info("=== Company {$company->getId()} ===");

        $created = 0;

        foreach ($vinTypes as $vinType) {
            $name = match (true) {
                is_string($vinType) => $vinType,
                is_array($vinType) && isset($vinType['name']) => (string) $vinType['name'],
                default => null,
            };

            if ($name === null || trim($name) === '') {
                continue;
            }

            // Store uppercased so the download path's LeadType lookup (which uppercases
            // the VinSolution type name) matches what we persist here.
            $name = strtoupper(trim($name));

            $exists = LeadType::fromApp($app)
                ->fromCompany($company)
                ->notDeleted()
                ->where('name', $name)
                ->exists();

            if ($exists) {
                continue;
            }

            new CreateLeadTypeAction(
                new LeadTypeData(
                    apps: $app,
                    companies: $company,
                    name: $name,
                    description: $name,
                    is_active: 1,
                )
            )->execute();
            $created++;
        }

        $this->info("Lead types synced: {$created}");
    }
}
