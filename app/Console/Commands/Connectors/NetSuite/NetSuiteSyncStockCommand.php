<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\NetSuite;

use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Console\Command;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\B2BSettingsEnums;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteProductStockAction;
use Kanvas\Users\Actions\SendUserNotificationAction;
use Kanvas\Users\Models\Users;
use League\Csv\Reader;

class NetSuiteSyncStockCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:netsuite-sync-stock {app_id} {company_id} {user_id} {filePath?} {--saved-search-id=576 : NetSuite saved search ID to use}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Sync all products stock from NetSuite in a single optimized call';

    public function handle(): void
    {
        $app = Apps::getById($this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById($this->argument('company_id'));
        $user = Users::getById($this->argument('user_id'));

        $savedSearchId = $this->option('saved-search-id');
        $filePath = $this->argument('filePath');

        // Get optional product list from CSV
        $barcodeFilter = null;
        $totalInCsv = 0;
        if ($filePath) {
            $this->info("Loading product list from CSV: {$filePath}");
            $csvProductList = $this->getProductList($filePath);
            $barcodeFilter = array_keys($csvProductList);
            $totalInCsv = count($barcodeFilter);
            $this->info("Loaded {$totalInCsv} products from CSV");
        }

        $this->info("Starting bulk stock sync from NetSuite saved search ID: {$savedSearchId}");

        try {
            $syncAction = new PullNetSuiteProductStockAction(
                $app,
                $company,
                $user
            );

            $this->info("Fetching all products from NetSuite (this may take a moment for large catalogs)...");
            $results = $syncAction->execute($savedSearchId, $barcodeFilter);

            if (isset($results['error'])) {
                $this->error("Error: {$results['error']}");
                return;
            }

            // Display results
            $this->newLine();
            $this->info("=== Sync Results ===");

            if ($barcodeFilter) {
                $this->info("Products in CSV file: {$totalInCsv}");
                $this->info("Found in NetSuite: " . ($totalInCsv - $results['not_found_in_netsuite']));
                $this->warn("Not in NetSuite: {$results['not_found_in_netsuite']}");
            } else {
                $this->info("Total products from NetSuite: {$results['savedSearchTotal']}");
            }

            $this->info("Found in Kanvas: " . ($results['totalToProcess'] - $results['not_found_in_netsuite'] - $results['not_found_in_kanvas']));
            $this->warn("Not in Kanvas: {$results['not_found_in_kanvas']}");
            $this->info("Successfully updated: {$results['processed']}");

            if ($results['update_failed'] > 0) {
                $this->error("Failed to update: {$results['update_failed']}");
            }

            $this->newLine();

            // Display details of issues
            if (! empty($results['not_found_in_netsuite_list'])) {
                $notInNetSuite = $results['not_found_in_netsuite_list'];
                $this->warn("Products not found in NetSuite (" . count($notInNetSuite) . "):");
                foreach (array_slice($notInNetSuite, 0, 10) as $barcode) {
                    $this->line("  - {$barcode}");
                }
                if (count($notInNetSuite) > 10) {
                    $this->line("  ... and " . (count($notInNetSuite) - 10) . " more");
                }
                $this->newLine();
            }

            if (! empty($results['not_found_in_kanvas_list'])) {
                $notInKanvas = $results['not_found_in_kanvas_list'];
                $this->warn("Products not found in Kanvas (" . count($notInKanvas) . "):");
                foreach (array_slice($notInKanvas, 0, 10) as $barcode) {
                    $this->line("  - {$barcode}");
                }
                if (count($notInKanvas) > 10) {
                    $this->line("  ... and " . (count($notInKanvas) - 10) . " more");
                }
                $this->newLine();
            }

            if (! empty($results['update_failed_list'])) {
                $updateFailed = $results['update_failed_list'];
                $this->error("Products that failed to update (" . count($updateFailed) . "):");
                foreach (array_slice($updateFailed, 0, 10) as $failed) {
                    $this->line("  - {$failed['barcode']} (Error: {$failed['error']})");
                }
                if (count($updateFailed) > 10) {
                    $this->line("  ... and " . (count($updateFailed) - 10) . " more");
                }
                $this->newLine();
            }

            // Send notification to user
            new SendUserNotificationAction(
                $app,
                $company,
                $user,
                RolesEnums::INVENTORY_MANAGER,
            )->execute($app->get(B2BSettingsEnums::B2B_SYNC_INVENTORY_EMAIL_TEMPLATE->getValue()), []);

            $this->info("Stock sync completed successfully!");
        } catch (Exception $e) {
            $this->error("Error during bulk sync: " . $e->getMessage());
            $this->error("Stack trace: " . $e->getTraceAsString());
        }
    }

    private function getProductList(string $csvFilePath): array
    {
        $headerOffset = 0;
        $csv = Reader::createFromPath($csvFilePath);
        $csv->setHeaderOffset($headerOffset);
        $csv->skipEmptyRecords();
        $records = $csv->getRecords();

        $productList = [];
        foreach ($records as $offset => $record) {
            if ($offset < $headerOffset) {
                continue;
            }

            $barcode = $record['Copic Item No/ UPC'];
            $sku = $record["Macpherson  Item #"] ?? "";
            $productList[$barcode] = [
                "sku" => $sku,
                "name" => $record["Description"],
                "barcode" => $barcode
            ];
        }

        return $productList;
    }
}
