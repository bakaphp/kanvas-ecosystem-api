<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\NetSuite;

use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteCustomerAction;
use Kanvas\Users\Models\Users;
use League\Csv\Reader;

class NetSuiteSyncCustomerCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:netsuite-sync-customer {app_id} {company_id} {user_id} {filePath?} {--saved-search-id= : NetSuite saved search ID to use}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Sync all customer names from NetSuite in a single optimized call';

    public function handle(): void
    {
        $app = Apps::getById($this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById($this->argument('company_id'));
        $user = Users::getById($this->argument('user_id'));

        $savedSearchId = $this->option('saved-search-id');

        if (! $savedSearchId) {
            $this->error('Please provide a saved search ID using --saved-search-id option');
            return;
        }

        $filePath = $this->argument('filePath');

        // Get optional customer list from CSV
        $entityIdFilter = null;
        $totalInCsv = 0;
        if ($filePath) {
            $this->info("Loading customer list from CSV: {$filePath}");
            $csvCustomerList = $this->getCustomerList($filePath);
            $entityIdFilter = array_keys($csvCustomerList);
            $totalInCsv = count($entityIdFilter);
            $this->info("Loaded {$totalInCsv} customers from CSV");
        }

        $this->info("Starting bulk customer sync from NetSuite saved search ID: {$savedSearchId}");

        try {
            $syncAction = new PullNetSuiteCustomerAction(
                $app,
                $company,
                $user
            );

            $this->info("Fetching all customers from NetSuite (this may take a moment for large datasets)...");
            $results = $syncAction->execute($savedSearchId, $entityIdFilter);

            if (isset($results['error'])) {
                $this->error("Error: {$results['error']}");
                return;
            }

            // Display results
            $this->newLine();
            $this->info("=== Sync Results ===");

            if ($entityIdFilter) {
                $this->info("Customers in CSV file: {$totalInCsv}");
                $this->info("Found in NetSuite: " . ($totalInCsv - $results['not_found_in_netsuite']));
                $this->warn("Not in NetSuite: {$results['not_found_in_netsuite']}");
            } else {
                $this->info("Total customers from NetSuite: {$results['savedSearchTotal']}");
            }

            $this->info("Found in Kanvas: " . ($results['totalToProcess'] - $results['not_found_in_netsuite'] - $results['not_found_in_kanvas']));
            $this->warn("Not in Kanvas: {$results['not_found_in_kanvas']}");
            $this->info("Successfully updated: {$results['processed']}");

            if ($results['update_failed'] > 0) {
                $this->error("Failed to update: {$results['update_failed']}");
            }

            $this->newLine();

            // Display successfully updated customers
            if (! empty($results['processedList'])) {
                $processed = $results['processedList'];
                $this->info("Successfully updated customers (" . count($processed) . "):");
                foreach (array_slice($processed, 0, 10) as $customer) {
                    $this->line("  - NetSuite ID: {$customer['netsuiteId']}, Kanvas ID: {$customer['kanvasId']}, Name: {$customer['name']}");
                }
                if (count($processed) > 10) {
                    $this->line("  ... and " . (count($processed) - 10) . " more");
                }
                $this->newLine();
            }

            // Display details of issues
            if (! empty($results['not_found_in_netsuite_list'])) {
                $notInNetSuite = $results['not_found_in_netsuite_list'];
                $this->warn("Customers not found in NetSuite (" . count($notInNetSuite) . "):");
                foreach (array_slice($notInNetSuite, 0, 10) as $entityId) {
                    $this->line("  - {$entityId}");
                }
                if (count($notInNetSuite) > 10) {
                    $this->line("  ... and " . (count($notInNetSuite) - 10) . " more");
                }
                $this->newLine();
            }

            if (! empty($results['not_found_in_kanvas_list'])) {
                $notInKanvas = $results['not_found_in_kanvas_list'];
                $this->warn("Customers not found in Kanvas (" . count($notInKanvas) . "):");
                foreach (array_slice($notInKanvas, 0, 10) as $entityId) {
                    $this->line("  - {$entityId}");
                }
                if (count($notInKanvas) > 10) {
                    $this->line("  ... and " . (count($notInKanvas) - 10) . " more");
                }
                $this->newLine();
            }

            if (! empty($results['update_failed_list'])) {
                $updateFailed = $results['update_failed_list'];
                $this->error("Customers that failed to update (" . count($updateFailed) . "):");
                foreach (array_slice($updateFailed, 0, 10) as $failed) {
                    $this->line("  - {$failed['netsuiteId']} (Error: {$failed['error']})");
                }
                if (count($updateFailed) > 10) {
                    $this->line("  ... and " . (count($updateFailed) - 10) . " more");
                }
                $this->newLine();
            }

            $this->info("Customer sync completed successfully!");
        } catch (Exception $e) {
            $this->error("Error during bulk sync: " . $e->getMessage());
            $this->error("Stack trace: " . $e->getTraceAsString());
        }
    }

    private function getCustomerList(string $csvFilePath): array
    {
        $headerOffset = 0;
        $csv = Reader::createFromPath($csvFilePath);
        $csv->setHeaderOffset($headerOffset);
        $csv->skipEmptyRecords();
        $records = $csv->getRecords();

        $customerList = [];
        foreach ($records as $offset => $record) {
            if ($offset < $headerOffset) {
                continue;
            }

            // Assuming CSV has at least a 'NetSuite ID' or 'Internal ID' column
            $netsuiteId = $record['NetSuite ID'] ?? $record['Internal ID'] ?? $record['ID'] ?? null;

            if ($netsuiteId) {
                $customerList[$netsuiteId] = [
                    "id" => $netsuiteId,
                    "name" => $record["Name"] ?? $record["Customer Name"] ?? ""
                ];
            }
        }

        return $customerList;
    }
}
