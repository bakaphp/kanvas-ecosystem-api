<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;

class ScoutProductIndexCleanUpCommand extends Command
{
    use KanvasJobsTrait;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-inventory:scout-product-index-cleanup {app_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Clean scout deleted products';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $app = Apps::getById($this->argument('app_id'));
        $this->overwriteAppService($app);

        $this->info('Cleaning up scout index for deleted products App ' . $app->name);
        $products = Products::fromApp($app)->withTrashed()->where('is_published', 0)->orWhere('is_deleted', 1);

        $this->info('Total products to clean up: ' . $products->count());
        $products->unsearchable();

        return;
    }
}
