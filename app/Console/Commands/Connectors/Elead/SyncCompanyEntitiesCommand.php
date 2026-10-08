<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Elead;

use App\Console\Commands\Connectors\Concerns\ResolvesConfiguredCompanies;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Actions\SyncLeadSourceAction;
use Kanvas\Connectors\Elead\Actions\SyncLeadStatusAction;
use Kanvas\Connectors\Elead\Actions\SyncParticipantsTypesAction;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Throwable;

class SyncCompanyEntitiesCommand extends Command
{
    use KanvasJobsTrait;
    use ResolvesConfiguredCompanies;

    protected $signature = 'kanvas:elead-sync-company-entities
                            {app_id : The application ID}
                            {company_ids? : Comma-separated company IDs. Omit to auto-discover every eLeads-configured company for the app}';

    protected $description = 'Create the Kanvas lead sources, lead types, statuses and participant types an eLeads dealer needs before leads are downloaded.';

    public function handle(): void
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

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

        $this->info('=== eLeads company entities sync completed ===');
    }

    private function processCompany(Apps $app, Companies $company): void
    {
        if (! $company->get(CustomFieldEnum::COMPANY->value)) {
            $this->error("Company {$company->getId()} does not have eLeads configuration");

            return;
        }

        $this->info("=== Company {$company->getId()} ({$company->name}) ===");

        try {
            $sources = new SyncLeadSourceAction($app, $company, fresh: true)->execute();
            $this->info("Lead sources synced: {$sources}");

            new SyncLeadStatusAction($app, $company)->execute();
            $this->info('Lead statuses synced');

            new SyncParticipantsTypesAction($app, $company)->execute();
            $this->info('Participant types synced');
        } catch (Throwable $e) {
            $this->error('Failed to sync eLeads entities: ' . $e->getMessage());
        }
    }
}
