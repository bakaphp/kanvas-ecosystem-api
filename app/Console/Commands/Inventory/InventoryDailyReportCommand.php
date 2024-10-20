<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Products\Repositories\ProductsRepository;

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
        $company = Companies::getById($this->argument('company_id'));
        $this->overwriteAppService($app);

        $this->info('Sending Inventory Daily Report - ' . date('Y-m-d'));

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
