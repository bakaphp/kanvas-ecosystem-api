<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Shopify;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Shopify\Services\ShopifyInventoryService;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\UserCompanyApps;
use Throwable;

class ShopifyInventoryLevelDownloadCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:inventory-shopify-inventory-level-sync {app_id} {company_id} {warehouse_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Update all stocks from variants and added to warehouses';

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
        $warehouses = Warehouses::getByIdFromCompanyApp((int) $this->argument('warehouse_id'), $company, $app);

        $associatedApps = UserCompanyApps::where('apps_id', $app->getId())
                                ->where('companies_id', $company->getId())->first();

        $companyData = $associatedApps->company;

        $products = Products::where('companies_id', $companyData->getId())
        ->where('apps_id', $app->getId())
        ->orderBy('id', 'desc')
        ->get();

        foreach ($products as $product) {
            try {
                $this->info("Checking product {$product->getId()} {$product->name} \n");
                $shopifyProductId = $product->getShopifyId($warehouses->regions);

                if ($shopifyProductId != null) {
                    $shopifyService = new ShopifyInventoryService($product->app, $product->company, $warehouses);

                    foreach ($product->variants as $variant) {
                        if($variant->getShopifyId($warehouses->regions)) {
                            $inventoryItem = $shopifyService->getInventoryItemFromVariant($variant);
                            if(!empty($inventoryItem)){
                                $variant->updateQuantityInWarehouse($warehouses, (float) $inventoryItem[0]['available']);
                                $this->info("Checking variant {$variant->getId()} {$variant->name} {$inventoryItem[0]['available']} in stock\n");
                            };
                        }
                    }
                }
            } catch (Throwable $e) {
                $this->error($e->getMessage());
                $this->error("Error syncing product {$product->getId()} {$product->name} \n");
            }
        }

        return;
    }
}
