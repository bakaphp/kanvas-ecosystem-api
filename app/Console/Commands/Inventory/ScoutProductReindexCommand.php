<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;

class ScoutProductReindexCommand extends Command
{
    use KanvasJobsTrait;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-inventory:scout-product-reindex {app_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Reindex scout non-deleted products';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $app = Apps::getById($this->argument('app_id'));
        $this->overwriteAppService($app);

        $this->info('Reindex scout index for products App ' . $app->name);
        $products = Products::fromApp($app)->where('is_published', 1)->where('is_deleted', 0);

        $this->info('Total products to reindexed: ' . $products->count());
        $products->searchable();

        return;
    }
}
