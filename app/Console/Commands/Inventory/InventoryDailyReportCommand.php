<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Console\Command;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Notifications\LowStockNotification;
use Kanvas\Inventory\Products\Repositories\ProductsRepository;
use Kanvas\Users\Models\UserCompanyApps;
use Kanvas\Users\Services\UserRoleNotificationService;
use Kanvas\Workflow\Enums\WorkflowEnum;

class InventoryDailyReportCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-inventory:daily-report {app_id?} {company_id?} {--low-stock-threshold=200} {--product-type-id=} {--skip-expiration} {--skip-low-stock}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Send daily inventory report including expiration and low stock notifications';

    public function handle(): void
    {
        //@todo make this run for multiple apps by looking for them at apps settings flag
        $app = Apps::getById($this->argument('app_id'));
        $this->overwriteAppService($app);

        // If no company_id is provided, get all companies for the app
        if (! $this->argument('company_id')) {
            // Fetch all companies for the given app
            $companies = UserCompanyApps::where('apps_id', $app->getId())->get();

            foreach ($companies as $company) {
                $this->processCompany($app, $company->company);
            }
        } else {
            // If company_id is provided, fetch the specific company
            $company = Companies::getById($this->argument('company_id'));
            $this->processCompany($app, $company);
        }
    }

    protected function processCompany(AppInterface $app, CompanyInterface $company): void
    {
        $this->info('Processing Inventory Daily Report - ' . $company->name . ' - ' . date('Y-m-d'));

        // Process expiration date notifications (existing functionality)
        if (! $this->option('skip-expiration')) {
            $this->unPublishProductsByExpirationDate($app, $company);
            //$this->publishProductsByExpirationDate($app, $company);
        }

        // Process low stock notifications (new functionality)
        if (! $this->option('skip-low-stock')) {
            $this->checkLowStockProducts($app, $company);
        }

        $company->fireWorkflow(
            WorkflowEnum::UPDATED->value,
            true,
            [
                'company' => $company,
            ]
        );
    }

    protected function unPublishProductsByExpirationDate(AppInterface $app, CompanyInterface $company): void
    {
        $productsToUnPublished = ProductsRepository::getProductsWithPassedEndDate($app, $company)->get();

        foreach ($productsToUnPublished as $product) {
            $product->unPublish();
            $this->info('Product ' . $product->id . ' has been unpublished');
            //@todo send report to the company
        }
    }

    protected function publishProductsByExpirationDate(AppInterface $app, CompanyInterface $company): void
    {
        $productsToPublished = ProductsRepository::getProductWithUnPassedEndDate($app, $company)->get();

        foreach ($productsToPublished as $product) {
            $product->publish();
            $this->info('Product ' . $product->id . ' has been published');
            //@todo send report to the company
        }
    }

    protected function checkLowStockProducts(AppInterface $app, CompanyInterface $company): void
    {
        $lowStockThreshold = (int) $this->option('low-stock-threshold');
        $productTypeId = $this->option('product-type-id') ? (int) $this->option('product-type-id') : null;

        $lowStockProducts = ProductsRepository::getLowStockProducts(
            $app,
            $company,
            $lowStockThreshold,
            $productTypeId
        )->get();

        if ($lowStockProducts->isEmpty()) {
            $this->info('No low stock products found for company ' . $company->name);

            return;
        }

        $this->info('Found ' . $lowStockProducts->count() . ' products with low stock for company ' . $company->name);

        // Send low stock notification
        $this->sendLowStockNotification($app, $company, $lowStockProducts, $lowStockThreshold);

        // Log each low stock product
        foreach ($lowStockProducts as $product) {
            $this->warn('Low stock: ' . $product->product_name . ' - Stock: ' . $product->total_stock_quantity);
        }
    }

    protected function sendLowStockNotification(
        AppInterface $app,
        CompanyInterface $company,
        $lowStockProducts,
        int $lowStockThreshold
    ): void {
        try {
            // Check if company has low stock notifications enabled
            if (! $company->get('low_stock_notifications_enabled')) {
                $this->info('Low stock notifications are disabled for company ' . $company->name);

                return;
            }

            $notification = new LowStockNotification(
                $company,
                [
                    'products' => $lowStockProducts,
                    'lowStockThreshold' => $lowStockThreshold,
                    'company' => $company,
                    'app' => $app,
                    'user' => null, // Will be set per user in the notification service
                ]
            );

            // Send to company owners/admins
            UserRoleNotificationService::notify(
                RolesEnums::INVENTORY_MANAGER->value,
                $notification,
                $app
            );

            $this->info('Low stock notification sent to company owners for ' . $company->name);
        } catch (Exception $e) {
            $this->error('Failed to send low stock notification for company ' . $company->name . ': ' . $e->getMessage());
        }
    }
}
