<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Shopify;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\Shopify\Actions\DownloadAllShopifyProductsAction;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

class ShopifyInventoryDownloadCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:inventory-shopify-sync {app_id} {branch_id} {warehouse_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Download products from shopify to this warehouse';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $branch = CompaniesBranches::getById((int) $this->argument('branch_id'));
        $warehouse = Warehouses::fromApp($app)->where('id', $this->argument('warehouse_id'))->firstOrFail();

        $downloadProduct = new DownloadAllShopifyProductsAction(
            $warehouse->app,
            $warehouse,
            $branch,
            $branch->company->user
        );

        $total = $downloadProduct->execute();

        $this->info($total . ' Products downloaded successfully from Shopify to warehouse. Running queue');

        return;
    }
}
