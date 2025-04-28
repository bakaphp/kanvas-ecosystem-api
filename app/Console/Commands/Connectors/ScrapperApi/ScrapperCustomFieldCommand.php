<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ScrapperApi;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\ScrapperApi\Actions\SaveCustomFieldDataAction;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

class ScrapperCustomFieldCommand extends Command
{
    protected $signature = 'kanvas:scrapper-custom-field {app_id} {warehouse_id} {branch_id} {region_id}';

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $branch = CompaniesBranches::getById((int) $this->argument('branch_id'));
        $regions = Regions::getById((int) $this->argument('region_id'));
        $warehouse = Warehouses::getById((int) $this->argument('warehouse_id'));

        $products = Products::getByApp($app);
        foreach ($products as $product) {
            $action = new SaveCustomFieldDataAction(
                $warehouse,
                $product,
                $regions,
                $product->name,
                $product->getFiles()->map(function ($file) {
                    return $file->url;
                })->toArray(),
                $branch
            );
            $action->execute();
        }
    }
}
