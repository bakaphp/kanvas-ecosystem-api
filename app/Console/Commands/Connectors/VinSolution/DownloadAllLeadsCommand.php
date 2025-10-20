<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\VinSolution;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VinSolution\Actions\PullLeadAction;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Dealers\User;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Leads\Lead;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as EnumsConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead as GuildLead;
use Kanvas\Guild\Leads\Models\Lead as ModelsLead;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Throwable;

class DownloadAllLeadsCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:vinsolution-download-all-leads 
                            {app_id : The application ID}
                            {company_id : The company ID}
                            {user_id : The user ID}
                            {--from-first-page=0 : Start from first page (1) or continue from last position (0)}
                            {--total-page-limit= : Limit the total number of pages to process}
                            {--items-per-page=10 : Number of items per page}
                            {--sync-duplicates=1 : Sync duplicate active leads before download (1=yes, 0=no)}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Download all leads from VinSolution for a specific company';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $company = Companies::getById((int) $this->argument('company_id'));
        $user = Users::getById((int) $this->argument('user_id'));

        $this->overwriteAppService($app);

        // Check if company has VinSolution configuration
        if (! $company->get(ConfigurationEnum::COMPANY->value)) {
            $this->error('Company does not have VinSolution configuration');

            return;
        }

        // Check if user has VinSolution configuration
        $vinUserId = $user->get(ConfigurationEnum::getUserKey($company, $user));
        if (! $vinUserId) {
            $this->error('User does not have VinSolution configuration');

            return;
        }

        try {
            $dealer = Dealer::getById($company->get(ConfigurationEnum::COMPANY->value), $app);
            $vinUser = Dealer::getUser($dealer, $vinUserId, $app);
        } catch (Throwable $e) {
            $this->error('Failed to get VinSolution dealer or user: ' . $e->getMessage());

            return;
        }

        // Sync duplicate active leads before downloading (if enabled)
        $syncDuplicates = (bool) $this->option('sync-duplicates');
        if ($syncDuplicates) {
            $this->syncDuplicateActiveLeads($app, $company, $user, $dealer, $vinUser);
        }

        // Pagination settings
        $fromFirstPage = (bool) $this->option('from-first-page');
        $totalPageLimit = $this->option('total-page-limit') ? (int) $this->option('total-page-limit') : null;
        $itemsPerPage = (int) $this->option('items-per-page');

        // Redis key for pagination
        $redisPaginationKey = CustomFieldEnum::LEADS_PAGINATION->value . '_' . $company->getId();
        $lastLeadsPagination = $fromFirstPage ? 0 : (int) Redis::get($redisPaginationKey);

        // Get initial page to determine total pages
        $initialParams = [
            'leadStatusType' => 'Active',
            'sortBy' => 'Date',
            'pageNumber' => 1,
            'limit' => $itemsPerPage,
        ];

        try {
            $initialLeads = Lead::getAllV2($dealer, $vinUser, $initialParams);
            $totalItems = $initialLeads['count'];
            $totalPages = $totalPageLimit ?? (int) ceil($totalItems / $itemsPerPage);

            $this->info("Total leads: {$totalItems}");
            $this->info("Total pages: {$totalPages}");
            $this->info('Starting from page: ' . ($lastLeadsPagination < 1 ? 1 : $lastLeadsPagination));

            $successCount = 0;
            $errorCount = 0;

            // Create progress bar
            $progressBar = $this->output->createProgressBar($totalPages - $lastLeadsPagination);
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s% - %message%');
            $progressBar->setMessage('Starting download...');

            for ($i = $lastLeadsPagination < 1 ? 1 : $lastLeadsPagination; $i <= $totalPages; $i++) {
                $params = [
                    'leadStatusType' => 'Active',
                    'sortBy' => 'Date',
                    'pageNumber' => $i,
                    'limit' => $itemsPerPage,
                ];

                try {
                    $leads = Lead::getAllV2($dealer, $vinUser, $params);

                    foreach ($leads['items'] as $vinLeadData) {
                        try {
                            // Transform the lead data to match the expected format
                            $transformedLead = $this->transformVinLeadData($vinLeadData);

                            $existingLead = ModelsLead::getByCustomField(
                                CustomFieldEnum::LEADS->value,
                                $transformedLead['LeadId'],
                                $company,
                            );

                            // Use PullLeadAction to sync the lead
                            $pullLeadAction = new PullLeadAction($app, $company, $user);
                            $result = $pullLeadAction->execute(null, $transformedLead['LeadId']);

                            if ($existingLead === null) {
                                $this->setCommunicationChannel((string) $transformedLead['LeadId']);
                            }

                            if (! empty($result)) {
                                $successCount++;
                            }
                        } catch (Throwable $e) {
                            $errorCount++;
                            $this->warn("Failed to sync lead {$vinLeadData['leadId']}: " . $e->getMessage());
                        }
                    }

                    $progressBar->setMessage("Page {$i} - Success: {$successCount}, Errors: {$errorCount}");
                    $progressBar->advance();

                    // Update Redis pagination
                    if ($i === $totalPages) {
                        Redis::set($redisPaginationKey, 0); // Reset on completion
                    } else {
                        Redis::set($redisPaginationKey, $i + 1);
                    }
                } catch (Throwable $e) {
                    $this->error("Failed to process page {$i}: " . $e->getMessage());
                    Redis::set($redisPaginationKey, $i);

                    break;
                }
            }

            $progressBar->finish();
            $this->newLine(2);
            $this->info('Download completed!');
            $this->info("Successfully synced: {$successCount} leads");
            $this->info("Errors: {$errorCount} leads");
        } catch (Throwable $e) {
            $this->error('Failed to download leads: ' . $e->getMessage());
        }
    }

    private function setCommunicationChannel(string $leadId): void
    {
        $lead = GuildLead::getById($leadId);
        $lead->set('downloaded_from_vin_solution', true);
        $lead->refresh();

        $hasEmail = $lead->people?->getEmails()->count() > 0;
        $hasCellPhone = $lead->people?->getCellPhones()->count() > 0;

        $agentNotificationChannel = match (true) {
            $hasEmail && $hasCellPhone => 'sms',
            $hasEmail => 'email',
            $hasCellPhone => 'sms',
            default => null,
        };

        if ($agentNotificationChannel !== null) {
            $lead->set(EnumsConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value, $agentNotificationChannel);
        }

        $lead->fireWorkflow(
            WorkflowEnum::CREATED->value,
            true,
            [
                'app' => $lead->app,
            ]
        );
    }

    /**
     * Sync people's leads when they have 2 or more active leads.
     * VinSolution doesn't allow multiple active leads per person, so we need to sync them to update status correctly.
     */
    private function syncDuplicateActiveLeads(Apps $app, Companies $company, Users $user, Dealer $dealer, User $vinUser): void
    {
        $this->info('Checking for people with multiple active leads...');

        // Find people with 2 or more active leads (leads_status_id <= 2)
        $peopleWithMultipleActiveLeads = DB::connection('crm')->table('leads')
            ->select('people_id', DB::raw('COUNT(*) as active_leads_count'))
            ->where('companies_id', $company->getId())
            ->where('apps_id', $app->getId())
            ->where('leads_status_id', '<=', 2) // Active leads condition
            ->where('is_deleted', 0)
            ->groupBy('people_id')
            ->having('active_leads_count', '>=', 2)
            ->get();

        if ($peopleWithMultipleActiveLeads->isEmpty()) {
            $this->info('No people with multiple active leads found.');

            return;
        }

        $this->info("Found {$peopleWithMultipleActiveLeads->count()} people with multiple active leads. Syncing...");

        $syncCount = 0;
        $errorCount = 0;

        // Create progress bar for sync operation
        $syncProgressBar = $this->output->createProgressBar($peopleWithMultipleActiveLeads->count());
        $syncProgressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s% - %message%');
        $syncProgressBar->setMessage('Syncing duplicate active leads...');

        foreach ($peopleWithMultipleActiveLeads as $peopleData) {
            try {
                // Get all active leads for this person
                $activeLeads = ModelsLead::where('people_id', $peopleData->people_id)
                    ->where('companies_id', $company->getId())
                    ->where('apps_id', $app->getId())
                    ->where('leads_status_id', '<=', 2)
                    ->where('is_deleted', 0)
                    ->get();

                foreach ($activeLeads as $lead) {
                    // Check if lead has VinSolution lead ID
                    $vinLeadId = $lead->get(CustomFieldEnum::LEADS->value);

                    if ($vinLeadId) {
                        try {
                            // Use PullLeadAction to sync the lead from VinSolution
                            $pullLeadAction = new PullLeadAction($app, $company, $user);
                            $result = $pullLeadAction->execute(null, $vinLeadId);

                            if (! empty($result)) {
                                $syncCount++;
                            }
                        } catch (Throwable $e) {
                            $errorCount++;
                            $this->warn("Failed to sync lead {$lead->id} (VIN ID: {$vinLeadId}): " . $e->getMessage());
                        }
                    }
                }

                $syncProgressBar->setMessage("Synced person {$peopleData->people_id} - Success: {$syncCount}, Errors: {$errorCount}");
                $syncProgressBar->advance();
            } catch (Throwable $e) {
                $errorCount++;
                $this->warn("Failed to sync leads for person {$peopleData->people_id}: " . $e->getMessage());
                $syncProgressBar->advance();
            }
        }

        $syncProgressBar->finish();
        $this->newLine(2);
        $this->info('Duplicate leads sync completed!');
        $this->info("Successfully synced: {$syncCount} leads");
        $this->info("Sync errors: {$errorCount} leads");
        $this->newLine();
    }

    /**
     * Transform VinSolution lead data to match expected format.
     */
    private function transformVinLeadData(array $vinLeadData): array
    {
        return [
            'CustomerId' => $vinLeadData['contact']['id'] ?? null,
            'LeadSource' => $vinLeadData['leadSource']['leadSourceId'] ?? null,
            'newLeadType' => $vinLeadData['leadType'] ?? null,
            'LeadStatusType' => $vinLeadData['leadStatusType'] ?? null,
            'LeadId' => $vinLeadData['leadId'] ?? null,
            'IsOnShowroom' => $vinLeadData['isOnShowroom'] ?? false,
            'createdUtc' => $vinLeadData['createdUtc'] ?? null,
        ];
    }
}
