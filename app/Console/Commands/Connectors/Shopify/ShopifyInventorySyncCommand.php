<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Shopify;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Shopify\Enums\StatusEnum;
use Kanvas\Connectors\Shopify\Services\ShopifyInventoryService;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\UserCompanyApps;
use Throwable;

class ShopifyInventorySyncCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:inventory-shopify-sync {app_id} {company_id} {warehouse_id} {channel_id} {--product_id=}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Send all our local inventory to shopify';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById((int) $this->argument('company_id'));
        $channel = Channels::getByIdFromCompanyApp((int) $this->argument('channel_id'), $company, $app);
        $warehouses = Warehouses::getByIdFromCompanyApp((int) $this->argument('warehouse_id'), $company, $app);

        $associatedApps = UserCompanyApps::where('apps_id', $app->getId())
                                ->where('companies_id', $company->getId())->first();

        $companyData = $associatedApps->company;
        $this->info("Checking company {$companyData->getId()} \n");
        if ($productId = $this->option('product_id')) {
            $products = Products::where('companies_id', $companyData->getId())
                    ->where('apps_id', $app->getId())
                    ->where('id', $productId)
                    ->orderBy('id', 'desc')
                    ->get();
        } else {
            $products = Products::where('companies_id', $companyData->getId())
                ->where('apps_id', $app->getId())
                ->orderBy('id', 'desc')
                ->get();
        }

        foreach ($products as $product) {
            try {
                $this->info("Checking product {$product->getId()} {$product->name} \n");

                $shopifyService = new ShopifyInventoryService($product->app, $product->company, $warehouses);
                $shopifyService->saveProduct($product, StatusEnum::ACTIVE, $channel);
            } catch (Throwable $e) {
                $this->error($e->getMessage());
                $this->error("Error syncing product {$product->getId()} {$product->name} \n");
            }
        }

        return;
    }
}
