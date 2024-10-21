<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Repositories\ProductsRepository;
use Kanvas\Users\Models\UserCompanyApps;

class InventoryDailyReportCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-inventory:daily-report {app_id?} {company_id?}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Send daily report to the inventory module';

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
        $this->info('Sending Inventory Daily Report - ' . $company->name . ' - ' . date('Y-m-d'));
        $this->unPublishProductsByExpirationDate($app, $company);
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
}
