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
    protected $signature = 'kanvas:netsuite-sync-stock {app_id} {company_id} {user_id} {filePath?} {--saved-search-id=576 : NetSuite saved search ID to use} {--create-missing : Create the stock row for SKUs a mapped warehouse does not carry yet}';

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

            $this->info('Fetching all products from NetSuite (this may take a moment for large catalogs)...');
            $results = $syncAction->execute($savedSearchId, $barcodeFilter, (bool) $this->option('create-missing'));

            if (isset($results['error'])) {
                $this->error("Error: {$results['error']}");

                return;
            }

            $this->newLine();
            $this->info('=== Sync Results ===');

            if ($barcodeFilter) {
                $this->info("Products in CSV file: {$totalInCsv}");
            }

            $this->table(
                ['warehouse', 'location', 'in netsuite', 'updated', 'created', 'not stocked', 'not in kanvas', 'failed'],
                array_map(
                    fn (array $location) => [
                        $location['warehouses_id'],
                        $location['location_id'],
                        $location['error'] ?? $location['savedSearchTotal'],
                        count($location['processed'] ?? []),
                        count($location['created'] ?? []),
                        count($location['not_in_warehouse'] ?? []),
                        count($location['not_found_in_kanvas'] ?? []),
                        count($location['update_failed'] ?? []),
                    ],
                    $results['locations']
                )
            );

            $this->info("Successfully updated: {$results['processed']}");
            $this->info("  of which newly created: {$results['created']}");
            $this->warn("Not in Kanvas: {$results['not_found_in_kanvas']}");
            $this->warn("Not found in NetSuite: {$results['not_found_in_netsuite']}");

            // A mapped warehouse that carries none of the catalog is the symptom of a warehouse
            // that has never been stocked — --create-missing is what seeds it.
            if ($results['not_in_warehouse'] > 0) {
                $this->warn("Not stocked in the warehouse: {$results['not_in_warehouse']} (re-run with --create-missing to add them)");
            }

            if ($results['update_failed'] > 0) {
                $this->error("Failed to update: {$results['update_failed']}");
            }

            $this->newLine();

            foreach ($results['locations'] as $location) {
                if (isset($location['error'])) {
                    $this->error("Warehouse {$location['warehouses_id']}: {$location['error']}");

                    continue;
                }

                $this->reportSample("Warehouse {$location['warehouses_id']} — not found in NetSuite", $location['not_found_in_netsuite']);
                $this->reportSample("Warehouse {$location['warehouses_id']} — not found in Kanvas", $location['not_found_in_kanvas']);
                $this->reportSample("Warehouse {$location['warehouses_id']} — not stocked there", $location['not_in_warehouse']);

                foreach (array_slice($location['update_failed'], 0, 10) as $failed) {
                    $this->error("  - {$failed['barcode']} (Error: {$failed['error']})");
                }
            }

            // Send notification to user
            new SendUserNotificationAction(
                $app,
                $company,
                $user,
                RolesEnums::INVENTORY_MANAGER,
            )->execute($app->get(B2BSettingsEnums::B2B_SYNC_INVENTORY_EMAIL_TEMPLATE->getValue()), [
                'results' => $results,
            ]);

            $this->info('Stock sync completed successfully!');
        } catch (Exception $e) {
            $this->error('Error during bulk sync: ' . $e->getMessage());
            $this->error('Stack trace: ' . $e->getTraceAsString());
        }
    }

    /**
     * @param array<int, string> $barcodes
     */
    private function reportSample(string $label, array $barcodes): void
    {
        if ($barcodes === []) {
            return;
        }

        $this->warn($label . ' (' . count($barcodes) . '):');

        foreach (array_slice($barcodes, 0, 10) as $barcode) {
            $this->line('  - ' . $barcode);
        }

        if (count($barcodes) > 10) {
            $this->line('  ... and ' . (count($barcodes) - 10) . ' more');
        }

        $this->newLine();
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
            $sku = $record['Macpherson  Item #'] ?? '';
            $productList[$barcode] = [
                'sku' => $sku,
                'name' => $record['Description'],
                'barcode' => $barcode,
            ];
        }

        return $productList;
    }
}
