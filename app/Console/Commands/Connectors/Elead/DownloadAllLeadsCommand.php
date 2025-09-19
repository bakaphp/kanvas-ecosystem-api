<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Elead;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Actions\SyncLeadAction;
use Kanvas\Connectors\Elead\DataTransferObject\Lead as DataTransferObjectLead;
use Kanvas\Connectors\Elead\Entities\Lead;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Actions\SyncLeadByThirdPartyCustomFieldAction;
use Kanvas\Guild\Leads\Models\Lead as ModelsLead;
use Kanvas\Users\Models\Users;
use Throwable;

class DownloadAllLeadsCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:elead-download-all-leads 
                            {app_id : The application ID}
                            {company_id : The company ID}
                            {user_id : The user ID}
                            {--from= : Start date to download leads from (default: 24 hours ago)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download all leads from Elead for a specific company';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $appId = (int) $this->argument('app_id');
        $app = Apps::getById($appId);
        $company = Companies::getById((int) $this->argument('company_id'));
        $user = Users::getById((int) $this->argument('user_id'));

        $this->overwriteAppService($app);

        // Check if company has Elead configuration
        if (! $company->get(CustomFieldEnum::COMPANY->value)) {
            $this->error('Company does not have Elead configuration');

            return;
        }

        // Date settings
        $fromDateOption = $this->option('from');
        $fromDate = is_string($fromDateOption) ? $fromDateOption : date('Y-m-d', time() - 86400);

        $this->info('Starting Elead leads download');
        $this->info("Company: {$company->name} (ID: {$company->getId()})");
        $this->info("From date: {$fromDate}");

        $page = 1;
        $successCount = 0;
        $errorCount = 0;
        $existingCount = 0;

        try {
            do {
                $this->info("Processing page {$page}...");

                try {
                    $leads = Lead::searchByDelta($app, $company, $fromDate, $page);
                    $totalPages = $leads['totalPages'] ?? 1;
                    $totalItems = $leads['totalItems'] ?? 0;

                    if ($page === 1) {
                        $this->info("Total items found: {$totalItems}");
                        $this->info("Total pages: {$totalPages}");
                    }

                    /** @var array<string, mixed> $leads */
                    foreach ($leads['items'] as $item) {
                        /** @var array<string, mixed> $item */
                        try {
                            $leadId = (string) ($item['id'] ?? '');
                            $customerId = isset($item['customer']['id']) ? (string) $item['customer']['id'] : null;

                            // Check if lead already exists
                            $existingLead = ModelsLead::getByCustomField(
                                CustomFieldEnum::OPPORTUNITY_ID->value,
                                $leadId,
                                $company
                            );

                            if ($existingLead) {
                                $existingCount++;
                                $this->line("Lead {$leadId} already exists as {$existingLead->id} - updating");

                                new SyncLeadAction($existingLead)->execute();
                            } else {
                                $this->line("Creating new Lead {$leadId}");

                                // Create new Lead entity
                                $lead = new Lead();
                                $lead->company = $company;
                                $lead->app = $app;
                                $lead->customerId = $customerId;
                                $lead->assign($item);

                                // Create DTO and sync
                                $leadDto = DataTransferObjectLead::fromLeadEntity($lead, $user);
                                $syncAction = new SyncLeadByThirdPartyCustomFieldAction($leadDto);
                                $newLead = $syncAction->execute();

                                new SyncLeadAction($newLead)->execute();

                                $successCount++;
                            }
                        } catch (Throwable $e) {
                            $errorCount++;
                            $leadId = isset($item['id']) ? (string) $item['id'] : 'unknown';
                            $this->warn("Failed to sync lead {$leadId}: " . $e->getMessage());
                        }
                    }
                    $page++;
                } catch (Throwable $e) {
                    $this->error("Failed to fetch page {$page}: " . $e->getMessage());

                    break;
                }
            } while ($page <= ($totalPages ?? 1));

            $this->newLine(2);
            $this->info('Download completed!');
            $this->info("Successfully created: {$successCount} leads");
            $this->info("Updated existing: {$existingCount} leads");
            $this->info("Errors: {$errorCount} leads");
            $this->info('Total processed: ' . ($successCount + $existingCount + $errorCount) . ' leads');
        } catch (Throwable $e) {
            $this->error('Failed to download leads: ' . $e->getMessage());
        }
    }
}
