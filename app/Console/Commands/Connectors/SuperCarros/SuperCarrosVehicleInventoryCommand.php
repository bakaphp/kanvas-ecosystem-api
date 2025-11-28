<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\SuperCarros;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SuperCarros\Actions\SuperCarrosVehicleInventoryImportAction;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Throwable;

class SuperCarrosVehicleInventoryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:supercarros-vehicle-inventory 
                            {app_id : The application ID} 
                            {user_id : The user ID} 
                            {company_id : The company ID} 
                            {region_id : The region ID}
                            {--warehouse_id= : Optional warehouse ID to use instead of the region\'s default warehouse}
                            {--channel_id= : Optional channel ID to use instead of the company\'s default channel}
                            {--unpublish-all : Unpublish all variants from the channel before importing}
                            {--customer_id= : Optional customer ID to filter vehicles by customer}
                            {--weight= : Optional weight to assign to imported products}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Import all vehicles from SuperCarros API';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $company = Companies::getById((int) $this->argument('company_id'));
        $region = Regions::getById((int) $this->argument('region_id'));
        $user = Users::getById((int) $this->argument('user_id'));

        // Get optional warehouse if provided
        $warehouse = null;
        if ($warehouseId = $this->option('warehouse_id')) {
            $warehouse = Warehouses::getById((int) $warehouseId);

            // Validate warehouse belongs to the same company and region
            if ($warehouse->companies_id !== $company->id) {
                $this->error('The specified warehouse does not belong to the selected company.');

                return;
            }

            if ($warehouse->regions_id !== $region->id) {
                $this->error('The specified warehouse does not belong to the selected region.');

                return;
            }
        }

        // Get optional channel if provided
        $channel = null;
        if ($channelId = $this->option('channel_id')) {
            $channel = Channels::getById((int) $channelId, $company);
        }

        // Check if unpublish all flag is set
        $unpublishAll = $this->option('unpublish-all');
        $customerId = $this->option('customer_id');
        $weight = $this->option('weight') ? (float) $this->option('weight') : null;

        // Show configuration
        $this->info('Starting SuperCarros vehicle inventory import...');
        $this->info('App: ' . $app->name . ' (ID: ' . $app->id . ')');
        $this->info('Company: ' . $company->name . ' (ID: ' . $company->id . ')');
        $this->info('Region: ' . $region->name . ' (ID: ' . $region->id . ')');
        $this->info('User: ' . $user->displayname . ' (ID: ' . $user->id . ')');
        if ($customerId) {
            $this->info('Customer ID: ' . $customerId);
        }

        if ($weight !== null) {
            $this->info('Weight: ' . $weight);
        }

        if ($warehouse) {
            $this->info('Using warehouse: ' . $warehouse->name . ' (ID: ' . $warehouse->id . ')');
        } else {
            $defaultWarehouse = $region->defaultWarehouse;
            if ($defaultWarehouse) {
                $this->info('Using default warehouse: ' . $defaultWarehouse->name . ' (ID: ' . $defaultWarehouse->id . ')');
            } else {
                $this->error('No default warehouse found for the region.');

                return;
            }
        }

        if ($channel) {
            $this->info('Using channel: ' . $channel->name . ' (ID: ' . $channel->id . ')');
        } else {
            $this->info('Using default channel for company');
        }

        if ($unpublishAll) {
            $this->warn('Will unpublish all variants from channel before importing');
        }

        $this->info('');

        try {
            // Create and execute the import action
            $importAction = new SuperCarrosVehicleInventoryImportAction(
                $app,
                $company,
                $user,
                $region,
                $warehouse,
                $channel,
                $unpublishAll,
                $customerId,
                $weight
            );

            $this->info('Fetching vehicles from SuperCarros API...');
            $result = $importAction->execute();

            if (! $result['success']) {
                $this->error('Import failed!');
                foreach ($result['errors'] as $error) {
                    $this->error('• ' . $error);
                }

                return;
            }

            // Show progress and results
            $this->info('Import completed successfully!');
            $this->info('');
            $this->info('=== Import Summary ===');
            $this->info('Total vehicles processed: ' . $result['total_processed']);
            $this->info('Successfully imported: ' . $result['imported']);
            $this->info('Failed imports: ' . $result['failed']);
            $this->info('===================');

            // Show errors if any
            if (! empty($result['errors'])) {
                $this->warn('');
                $this->warn('Import Errors:');
                foreach ($result['errors'] as $error) {
                    $this->warn('• ' . $error);
                }
            }

            // Show imported products
            if (! empty($result['products'])) {
                $this->info('');
                $this->info('Imported Products:');
                foreach ($result['products'] as $product) {
                    $this->info('• ' . $product->name . ' (SKU: ' . $product->variants->first()->sku . ')');
                }
            }
        } catch (Throwable $e) {
            $this->error('An unexpected error occurred: ' . $e->getMessage());
            $this->error('File: ' . $e->getFile() . ':' . $e->getLine());

            if ($this->getOutput()->isVerbose()) {
                $this->error('Stack trace:');
                $this->error($e->getTraceAsString());
            }
        }
    }
}
