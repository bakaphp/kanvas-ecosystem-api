<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\VinSolution;

use App\Console\Commands\Connectors\VinSolution\Concerns\InteractsWithVinSolutionCompanies;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VinSolution\ClientCredential;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Leads\Source;
use Kanvas\Guild\LeadSources\Actions\CreateLeadSourceAction;
use Kanvas\Guild\LeadSources\DataTransferObject\LeadSource as LeadSourceData;
use Throwable;

class SyncLeadSourcesCommand extends Command
{
    use InteractsWithVinSolutionCompanies;
    use KanvasJobsTrait;

    protected $signature = 'kanvas:vinsolution-sync-lead-sources
                            {app_id : The application ID}
                            {company_ids? : Comma-separated company IDs. Omit to auto-discover every VinSolution-configured company for the app}
                            {--from-first-page=0 : Start from the first page (1) or continue from the last saved position (0)}';

    protected $description = 'Download all VinSolution lead sources for the configured companies and create the matching Kanvas lead sources.';

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

        foreach ($companies as $company) {
            $this->processCompany($app, $company);
            $this->newLine();
        }

        $this->info('=== VinSolution lead source sync completed ===');
    }

    private function processCompany(Apps $app, Companies $company): void
    {
        if (! $company->get(ConfigurationEnum::COMPANY->value)) {
            $this->error("Company {$company->getId()} does not have VinSolution configuration");

            return;
        }

        $operatingUser = $this->resolveOperatingUser($company);

        if ($operatingUser === null || ! $operatingUser->get(ConfigurationEnum::getUserKey($company, $operatingUser))) {
            $this->error("Company {$company->getId()} has no operating user with VinSolution configuration");

            return;
        }

        $this->info("=== Company {$company->getId()} ===");

        try {
            $credential = ClientCredential::get($company, $operatingUser, $app);
        } catch (Throwable $e) {
            $this->error('Failed to resolve VinSolution credentials: ' . $e->getMessage());

            return;
        }

        $dealer = $credential->dealer;
        $vinUser = $credential->user;

        try {
            $firstPage = Source::getAll($dealer, $vinUser, ['pagenumber' => 1]);
        } catch (Throwable $e) {
            $this->error('Failed to fetch lead sources: ' . $e->getMessage());

            return;
        }

        if ($firstPage === []) {
            $this->info('No lead sources returned.');

            return;
        }

        $pageInfo = reset($firstPage);
        $itemsPerPage = $pageInfo->itemsPerPage > 0 ? $pageInfo->itemsPerPage : 10;
        $totalPages = (int) ceil($pageInfo->total / $itemsPerPage);

        $redisPaginationKey = CustomFieldEnum::LEADS_PAGINATION->value . '_SOURCE_' . $company->getId();
        $startPage = (bool) $this->option('from-first-page') ? 1 : max(1, (int) Redis::get($redisPaginationKey));

        $this->info("Total pages: {$totalPages}, starting from page {$startPage}");

        $created = 0;

        for ($page = $startPage; $page <= $totalPages; $page++) {
            try {
                $sources = Source::getAll($dealer, $vinUser, ['pagenumber' => $page]);
            } catch (Throwable $e) {
                $this->error("Failed on page {$page}: " . $e->getMessage());
                Redis::set($redisPaginationKey, $page);

                break;
            }

            foreach ($sources as $sourceId => $source) {
                $localSource = new CreateLeadSourceAction(
                    new LeadSourceData(
                        app: $app,
                        company: $company,
                        leads_types_id: null,
                        name: $source->name,
                        is_active: true,
                        description: (string) $sourceId
                    )
                )->execute();

                $localSource->set(CustomFieldEnum::LEADS_SOURCE_ID->value, (int) $sourceId);
                $created++;
            }

            Redis::set($redisPaginationKey, $page === $totalPages ? 0 : $page + 1);
        }

        $this->info("Lead sources synced: {$created}");
    }
}
