<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Shopify;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Shopify\Enums\StatusEnum;
use Kanvas\Connectors\Shopify\Services\ShopifyInventoryService;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\UserCompanyApps;

class ShopifyInventorySyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:inventory-shopify-sync {app_id} {company_id} {warehouse_id} {channel_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Set defaults entities value for inventory companies';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $company = Companies::getById((int) $this->argument('company_id'));
        $channel = Channels::getByIdFromCompany((int) $this->argument('channel_id'), $company);
        $warehouses = Warehouses::getByIdFromCompany((int) $this->argument('warehouse_id'), $company);

        $associatedApps = UserCompanyApps::where('apps_id', $app->getId())
                                ->where('companies_id', $company->getId())->first();

        $companyData = $associatedApps->company;
        $this->info("Checking company {$companyData->getId()} \n");

        $products = Products::where('companies_id', $companyData->getId())
                    ->where('apps_id', $app->getId())
                    ->get();

        foreach ($products as $product) {
            $this->info("Checking product {$product->getId()} {$product->name} \n");

            $shopifyService = new ShopifyInventoryService($product->app, $product->company, $warehouses);
            $shopifyService->saveProduct($product, StatusEnum::ACTIVE, $channel);
        }
        return;
    }
}
