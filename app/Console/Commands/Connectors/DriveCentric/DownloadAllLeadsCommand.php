<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\DriveCentric;

use Baka\Traits\KanvasJobsTrait;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DriveCentric\Actions\PullLeadAction;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;
use Kanvas\Connectors\DriveCentric\Services\LeadService;
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
    protected $signature = 'kanvas:drivecentric-download-leads
                            {app_id : The application ID}
                            {--company_ids= : Comma-separated company IDs (optional - will auto-detect from config if not provided)}
                            {--start= : Start date in YYYY-MM-DD format (defaults to today)}
                            {--end= : End date in YYYY-MM-DD format (defaults to today)}
                            {--from-offset=0 : Start from specific offset (0) or continue from last position}
                            {--reset-pagination=0 : Reset pagination to start from beginning (1=yes, 0=no)}
                            {--filter-today=1 : Only process deals created TODAY with activity TODAY (0 = all deals)}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Download all leads/deals from DriveCentric for companies with DriveCentric configuration';

    public function handle(): void
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        // Get companies - either from option or auto-detect from config
        $companies = $this->getCompanies($app);

        if ($companies->isEmpty()) {
            $this->error('No companies found with DriveCentric configuration.');

            return;
        }

        // Get date range (default to today only - API only supports date, not time)
        $startDate = $this->option('start') ?: Carbon::now()->format('Y-m-d');
        $endDate = $this->option('end') ?: Carbon::now()->format('Y-m-d');

        $companiesCount = $companies->count();
        $this->info("Processing {$companiesCount} companies with DriveCentric configuration...");
        $this->info("Date range: {$startDate} to {$endDate}");
        $this->newLine();

        // Process each company sequentially
        foreach ($companies as $index => $company) {
            try {
                $this->info("=== Processing Company: {$company->name} (ID: {$company->getId()}) ({" . ($index + 1) . "}/{$companiesCount}) ===");
                $this->newLine();

                $this->processCompany($app, $company, $startDate, $endDate);
            } catch (Throwable $e) {
                $this->error("Failed to process company {$company->getId()}: " . $e->getMessage());
            }

            $this->newLine();

            // Add a small delay between companies to reduce rate limiting risk
            if ($index < $companies->count() - 1) {
                $this->info('Waiting 3 seconds before processing next company...');
                sleep(3);
                $this->newLine();
            }
        }

        $this->info('=== All companies processed ===');
    }

    /**
     * Get companies to process.
     */
    private function getCompanies(Apps $app): Collection
    {
        $companyIdsOption = $this->option('company_ids');

        if ($companyIdsOption) {
            // Use provided company IDs
            $companyIds = array_map('trim', explode(',', $companyIdsOption));

            return Companies::whereIn('id', $companyIds)->get();
        }

        // Auto-detect companies with DriveCentric store_id configuration
        // Query companies_settings table directly since Companies uses HashTableTrait
        $companyIds = DB::table('companies_settings')
            ->where('name', ConfigurationEnum::STORE_ID->value)
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->where('value', '!=', '0')
            ->pluck('companies_id');

        return Companies::query()
            ->whereIn('id', $companyIds)
            ->get();
    }

    /**
     * Process a single company's leads download.
     */
    private function processCompany(
        Apps $app,
        Companies $company,
        string $startDate,
        string $endDate
    ): void {
        // Verify DriveCentric configuration
        $storeId = $company->get(ConfigurationEnum::STORE_ID->value);
        if (! $storeId) {
            $this->error('Company does not have DriveCentric store ID configured');

            return;
        }

        // Get user for this company (use company owner or first admin)
        $user = $this->getCompanyUser($company);
        if (! $user) {
            $this->error('No user found for company');

            return;
        }

        $this->info("Using user: {$user->displayname} (ID: {$user->getId()})");
        $this->info("Store ID: {$storeId}");

        try {
            $leadService = new LeadService($app, $company);
            $pullLeadAction = new PullLeadAction($app, $company, $user);
        } catch (Throwable $e) {
            $this->error('Failed to initialize DriveCentric services: ' . $e->getMessage());

            return;
        }

        // Redis keys for tracking pagination
        $redisPaginationKey = 'drivecentric_leads_pagination_' . $company->getId();
        $resetPagination = (bool) $this->option('reset-pagination');
        $fromOffset = (int) $this->option('from-offset');
        $filterToday = (bool) $this->option('filter-today');

        // Get starting offset
        $currentOffset = $resetPagination ? 0 : ($fromOffset ?: (int) Redis::get($redisPaginationKey));

        // Calculate cutoff time for filtering
        $todayStart = Carbon::now()->startOfDay();

        $this->info("Starting from offset: {$currentOffset}");
        if ($filterToday) {
            $this->info("Filtering: createDate >= {$todayStart->toDateString()} AND latestAttemptDate >= {$todayStart->toDateString()}");
            $this->info('(Only syncing deals created TODAY with activity TODAY)');
        } else {
            $this->info('Filter disabled - processing ALL deals');
        }

        $successCount = 0;
        $errorCount = 0;
        $totalProcessed = 0;
        $skippedCount = 0;
        $hasMore = true;

        // Create progress indicator
        $this->info('Fetching deals...');

        while ($hasMore) {
            try {
                $response = $leadService->getDealsByRange(
                    $startDate,
                    $endDate,
                    $currentOffset,
                    includeMeta: true
                );

                $deals = $response['data'] ?? [];
                $meta = $response['meta'] ?? [];
                $total = $meta['total'] ?? 0;

                if ($totalProcessed === 0) {
                    $this->info("Total deals found: {$total}");
                    if ($total === 0) {
                        $this->info('No deals to process.');

                        break;
                    }
                }

                if (empty($deals)) {
                    $hasMore = false;

                    break;
                }

                foreach ($deals as $deal) {
                    try {
                        // Filter logic: Only process deals created TODAY with activity TODAY
                        if ($filterToday) {
                            $createDate = isset($deal['createDate']) ? Carbon::parse($deal['createDate']) : null;
                            $latestAttemptDate = isset($deal['latestAttemptDate']) ? Carbon::parse($deal['latestAttemptDate']) : null;

                            // Skip if deal was not created today
                            if (! $createDate || $createDate->lt($todayStart)) {
                                $skippedCount++;

                                continue;
                            }

                            // Skip if no activity today (no attempt today)
                            if (! $latestAttemptDate || $latestAttemptDate->lt($todayStart)) {
                                $skippedCount++;

                                continue;
                            }
                        }

                        $dealId = $this->extractDealId($deal);

                        if (! $dealId) {
                            $this->warn('Deal has no CrmId, skipping...');
                            $errorCount++;

                            continue;
                        }

                        // Use cache lock to prevent duplicate processing
                        $lockKey = "drivecentric_deal_sync:{$company->getId()}:{$dealId}";

                        Cache::lock($lockKey, 10)->block(10, function () use ($pullLeadAction, $deal, &$successCount): void {
                            $syncLead = $pullLeadAction->syncDeal($deal);
                            $syncLead->set('downloaded_from_drivecentric', 1);
                            $successCount++;
                        });

                        $totalProcessed++;
                    } catch (Throwable $e) {
                        $errorCount++;
                        $dealId = $this->extractDealId($deal) ?? 'unknown';
                        $this->warn("Failed to sync deal {$dealId}: " . $e->getMessage());
                    }
                }

                $skippedMsg = $skippedCount > 0 ? ", Skipped (old/inactive): {$skippedCount}" : '';
                $this->info("Progress: {$totalProcessed}/{$total} - Success: {$successCount}, Errors: {$errorCount}{$skippedMsg}");

                // Check if there are more pages
                $nextOffset = $meta['next'] ?? null;
                if ($nextOffset !== null && ! empty($deals)) {
                    $currentOffset = $meta['offset'] + count($deals);
                    Redis::set($redisPaginationKey, $currentOffset);
                } else {
                    $hasMore = false;
                    Redis::set($redisPaginationKey, 0); // Reset on completion
                }
            } catch (Throwable $e) {
                $this->error("Failed to fetch deals at offset {$currentOffset}: " . $e->getMessage());
                Redis::set($redisPaginationKey, $currentOffset);
                $hasMore = false;
            }
        }

        $this->newLine();
        $this->info('Download completed!');
        $this->info("Successfully synced: {$successCount} deals");
        $this->info("Errors: {$errorCount} deals");
        if ($skippedCount > 0) {
            $this->info("Skipped (not created today or no activity today): {$skippedCount} deals");
        }
    }

    /**
     * Get a user for the company.
     */
    private function getCompanyUser(Companies $company): ?Users
    {
        return $company->user;
    }

    /**
     * Extract deal CrmId from deal data.
     */
    private function extractDealId(array $deal): ?string
    {
        foreach ($deal['identifiers'] ?? [] as $identifier) {
            if (($identifier['type'] ?? '') === 'CrmId') {
                return $identifier['value'] ?? null;
            }
        }

        return null;
    }
}
