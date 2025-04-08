<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Shopify;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\Shopify\Actions\DownloadAllShopifyProductsAction;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

class ShopifyInventoryDownloadCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:inventory-download-from-shopify-sync {app_id} {branch_id} {warehouse_id} {channel_id?} {--sku=} {--product_id=} {--handle=}';

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
        $warehouse = Warehouses::fromApp($app)->fromCompany($branch->company)->where('id', $this->argument('warehouse_id'))->firstOrFail();
        $channel = $this->argument('channel_id') ? Channels::fromApp($app)->fromCompany($branch->company)->where('id', $this->argument('channel_id'))->firstOrFail() : null;

        $downloadProduct = new DownloadAllShopifyProductsAction(
            $warehouse->app,
            $warehouse,
            $branch,
            $branch->company->user,
            $channel
        );

        $params = [];
        if ($this->option('sku') !== '') {
            $params['sku'] = $this->option('sku');
        }
        if ($this->option('product_id') !== '') {
            $params['product_id'] = $this->option('product_id');
        }
        if ($this->option('handle') !== '') {
            $params['handle'] = $this->option('handle');
        }

        $total = $downloadProduct->execute($params);

        $this->info($total . ' Products downloaded successfully from Shopify to warehouse. Running queue');

        return;
    }
}
