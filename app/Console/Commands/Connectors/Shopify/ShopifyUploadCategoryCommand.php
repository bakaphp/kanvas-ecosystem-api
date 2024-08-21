<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Shopify;

use Illuminate\Console\Command;
use Kanvas\Connectors\Shopify\Actions\UploadCategoriesToCollectionAction;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

class ShopifyUploadCategoryCommand extends Command
{
    protected $signature = "kanvas:upload-categories-to-shopify {app_id} {categories_id} {warehouse_id} {shopify_id}";
    protected $description = "
    ";

    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $categories = Categories::getById((int) $this->argument('categories_id'));
        $warehouses = Warehouses::getById((int) $this->argument('warehouse_id'));
        $shopifyId = $this->argument('shopify_id');
        (new UploadCategoriesToCollectionAction($categories, $app, $warehouses, $shopifyId))->execute();
    }
}
