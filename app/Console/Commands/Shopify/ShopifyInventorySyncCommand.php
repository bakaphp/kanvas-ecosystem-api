<?php

declare(strict_types=1);

namespace App\Console\Commands\Shopify;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Shopify\Enums\StatusEnum;
use Kanvas\Connectors\Shopify\Services\ShopifyInventoryService;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Users\Models\UserCompanyApps;

class ShopifyInventorySyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:inventory-shopify-sync {app_id} {company_id}';

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

        $associatedApps = UserCompanyApps::where('apps_id', $app->getId())
        ->where('companies_id', $company->getId())->first();

        $companyData = $associatedApps->company;
        $this->info("Checking company {$companyData->getId()} \n");

        $products = Products::where('companies_id', $companyData->getId())
        ->where('apps_id', $app->getId())
        ->get();

        foreach ($products as $product) {
            $this->info("Checking product {$product->getId()} {$product->name} \n");

            foreach($product->variants as $variant) {
                $variant->warehouses->map(function ($warehouses) use ($variant) {
                    $shopifyService = new ShopifyInventoryService($variant->app, $variant->company, $warehouses);
                    $shopifyService->saveProduct($variant->product, StatusEnum::ACTIVE);
                });
            }
        }
        return;
    }
}
