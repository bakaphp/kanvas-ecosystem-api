<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
use function Laravel\Prompts\select;

class ScoutProductIndexCleanUpCommand extends Command
{
    use KanvasJobsTrait;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-inventory:scout-product-index-process {app_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Process scout products with actions';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $app = Apps::getById($this->argument('app_id'));
        $this->overwriteAppService($app);

        $option = select(
            label: 'Select the type of function to be done',
            options: [
                1 => 'Delete',
                2 => 'Reindex',
            ],
        );
        $this->executeAction($option, $app);

        return;
    }

    protected function executeAction(int $option, Apps $app)
    {
        $actions = [
            1 => fn() => $this->delete($app),
            2 => fn() => $this->reindex($app),
        ];

        if (isset($actions[$option])) {
            $actions[$option]();
        } else {
            $this->error('Invalid option selected.');
        }
    }

    public function reindex(Apps $app)
    {
        $this->info('Reindex scout index for products App ' . $app->name);
        $products = Products::fromApp($app)->where('is_published', 1)->where('is_deleted', 0);

        $this->info('Total products to reindexed: ' . $products->count());
        $products->searchable();
    }

    public function delete(Apps $app)
    {
        $this->info('Cleaning up scout index for deleted products App ' . $app->name);
        $products = Products::fromApp($app)->withTrashed()->where('is_published', 0)->orWhere('is_deleted', 1);

        $this->info('Total products to clean up: ' . $products->count());
        $products->unsearchable();
    }
}
