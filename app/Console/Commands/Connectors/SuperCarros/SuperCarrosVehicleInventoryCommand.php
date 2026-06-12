<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\SuperCarros;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SuperCarros\Actions\SuperCarrosImportAllCompaniesAction;
use Kanvas\Connectors\SuperCarros\Actions\SuperCarrosVehicleInventoryImportAction;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Throwable;

class SuperCarrosVehicleInventoryCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:supercarros-vehicle-inventory
                            {app_id : The application ID}
                            {--company_id= : Import a single company; omit to import EVERY company in the app that has SuperCarros configured}
                            {--user_id= : Optional user override; defaults to each company owner}
                            {--region_id= : Optional region override; defaults to each company default region}
                            {--warehouse_id= : Optional warehouse ID (single-company mode only)}
                            {--channel_id= : Optional channel ID (single-company mode only)}
                            {--unpublish-all : Unpublish all variants from the channel before importing}
                            {--customer_id= : Optional customer ID override (single-company mode only)}
                            {--weight= : Optional weight to assign to imported products}
                            {--email=* : Email addresses to send the import report to}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Import vehicles from SuperCarros. With --company_id imports one company; without it imports every company in the app that has SuperCarros configured.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));

        $this->overwriteAppService($app);

        $user = $this->option('user_id') ? Users::getById((int) $this->option('user_id')) : null;
        /** @var Regions|null $region */
        $region = $this->option('region_id') ? Regions::getById((int) $this->option('region_id')) : null;

        if ($this->option('company_id')) {
            $this->importSingleCompany($app, $user, $region);

            return;
        }

        $this->importAllCompanies($app, $user, $region);
    }

    /**
     * Import every company in the app that has SuperCarros configured (one cron per app).
     */
    private function importAllCompanies(Apps $app, ?Users $user, ?Regions $region): void
    {
        $weight = $this->option('weight') ? (float) $this->option('weight') : null;
        $unpublishAll = (bool) $this->option('unpublish-all');

        $this->info('Starting SuperCarros app-wide vehicle inventory import...');
        $this->info('App: ' . $app->name . ' (ID: ' . $app->id . ')');
        $this->info('Region: ' . ($region ? $region->name . ' (ID: ' . $region->id . ')' : 'each company default region'));
        $this->info('User: ' . ($user ? $user->displayname . ' (ID: ' . $user->id . ')' : 'each company owner'));

        $companies = SuperCarrosImportAllCompaniesAction::getConfiguredCompanies($app);
        if ($companies->isEmpty()) {
            $this->warn('No companies in this app have SuperCarros configured. Nothing to import.');

            return;
        }

        $this->info('Companies to import: ' . $companies->count());
        $this->info('');

        try {
            $result = new SuperCarrosImportAllCompaniesAction(
                $app,
                $unpublishAll,
                $weight,
                $user,
                $region
            )->execute();
        } catch (Throwable $e) {
            $this->error('An unexpected error occurred: ' . $e->getMessage());
            $this->error('File: ' . $e->getFile() . ':' . $e->getLine());

            if ($this->getOutput()->isVerbose()) {
                $this->error($e->getTraceAsString());
            }

            return;
        }

        $this->info('=== App-wide Import Summary ===');
        $this->info('Companies processed: ' . $result['companies_total']);
        $this->info('Companies succeeded: ' . $result['companies_succeeded']);
        $this->info('Companies failed: ' . $result['companies_failed']);
        $this->info('Total vehicles processed: ' . $result['total_processed']);
        $this->info('Successfully imported: ' . $result['imported']);
        $this->info('Failed imports: ' . $result['failed']);
        $this->info('==============================');

        foreach ($result['per_company'] as $company) {
            $line = '• ' . $company['company_name'] . ' (ID ' . $company['company_id'] . '): '
                . $company['imported'] . ' imported, ' . $company['failed'] . ' failed';
            $company['success'] ? $this->info($line) : $this->warn($line);
        }

        if (! empty($result['errors'])) {
            $this->warn('');
            $this->warn('Errors:');
            foreach ($result['errors'] as $error) {
                $this->warn('• ' . $error);
            }
        }

        $this->sendReportEmail(
            $app,
            null,
            $this->option('email'),
            [
                'success' => $result['companies_failed'] === 0,
                'total_processed' => $result['total_processed'],
                'imported' => $result['imported'],
                'failed' => $result['failed'],
                'errors' => $result['errors'],
            ]
        );
    }

    /**
     * Import a single company (legacy behaviour, driven by --company_id).
     */
    private function importSingleCompany(Apps $app, ?Users $user, ?Regions $region): void
    {
        $company = Companies::getById((int) $this->option('company_id'));

        // Region and user default to the company's own default region and owner.
        $region = $region ?: Regions::getDefault($company, $app);
        if (! $region) {
            $this->error('No region provided and no default region found for the company.');

            return;
        }
        /** @var Regions $region */
        /** @var Users|null $user */
        $user = $user ?: $company->user;
        if ($user === null) {
            $this->error('No user provided and no owner found for the company.');

            return;
        }
        $warehouse = null;
        if ($warehouseId = $this->option('warehouse_id')) {
            $warehouse = Warehouses::getById((int) $warehouseId);

            if ($warehouse->companies_id !== $company->id) {
                $this->error('The specified warehouse does not belong to the selected company.');

                return;
            }

            if ($warehouse->regions_id !== $region->id) {
                $this->error('The specified warehouse does not belong to the selected region.');

                return;
            }
        }

        $channel = null;
        if ($channelId = $this->option('channel_id')) {
            $channel = Channels::getById((int) $channelId, $company);
        }

        $unpublishAll = (bool) $this->option('unpublish-all');
        $customerId = $this->option('customer_id');
        $weight = $this->option('weight') ? (float) $this->option('weight') : null;

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

        $emails = $this->option('email');

        try {
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

                $this->sendReportEmail($app, $company, $emails, $result);

                return;
            }

            $this->info('Import completed successfully!');
            $this->info('');
            $this->info('=== Import Summary ===');
            $this->info('Total vehicles processed: ' . $result['total_processed']);
            $this->info('Successfully imported: ' . $result['imported']);
            $this->info('Failed imports: ' . $result['failed']);
            $this->info('===================');

            if (! empty($result['errors'])) {
                $this->warn('');
                $this->warn('Import Errors:');
                foreach ($result['errors'] as $error) {
                    $this->warn('• ' . $error);
                }
            }

            if (! empty($result['products'])) {
                $this->info('');
                $this->info('Imported Products:');
                foreach ($result['products'] as $product) {
                    $this->info('• ' . $product->name . ' (SKU: ' . $product->variants?->first()?->sku . ')');
                }
            }

            $this->sendReportEmail($app, $company, $emails, $result);
        } catch (Throwable $e) {
            $this->error('An unexpected error occurred: ' . $e->getMessage());
            $this->error('File: ' . $e->getFile() . ':' . $e->getLine());

            if ($this->getOutput()->isVerbose()) {
                $this->error('Stack trace:');
                $this->error($e->getTraceAsString());
            }
        }
    }

    /**
     * Send import report via email.
     */
    private function sendReportEmail(
        Apps $app,
        ?Companies $company,
        array $emails,
        array $result
    ): void {
        if (empty($emails)) {
            return;
        }

        $reportData = [
            'app' => $app,
            'company' => $company,
            'success' => $result['success'],
            'total_processed' => $result['total_processed'],
            'imported' => $result['imported'],
            'failed' => $result['failed'],
            'errors' => $result['errors'] ?? [],
            'date' => now()->format('Y-m-d H:i:s'),
        ];

        $subject = $result['success']
            ? 'SuperCarros Import Report - ' . $result['imported'] . ' imported, ' . $result['failed'] . ' failed'
            : 'SuperCarros Import Report - FAILED';

        foreach ($emails as $email) {
            try {
                $notification = new Blank(
                    'supercarros-import-report',
                    $reportData,
                    ['mail'],
                    $app
                );
                $notification->setSubject($subject);

                Notification::route('mail', $email)->notify($notification);
                $this->info('Report sent to ' . $email);
            } catch (Throwable $e) {
                $this->warn('Failed to send report to ' . $email . ': ' . $e->getMessage());
            }
        }
    }
}
