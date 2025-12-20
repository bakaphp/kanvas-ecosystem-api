<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;

use function Laravel\Prompts\select;

class ScoutProductIndexProcessCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-inventory:scout-product-index-process {app_id} {--company_id=}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Process scout products with actions';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $app = Apps::getById($this->argument('app_id'));
        $this->overwriteAppService($app);

        $companyId = $this->option('company_id') ?? null;

        $option = select(
            label: 'Select the type of function to be done',
            options: [
                1 => 'Delete',
                2 => 'Reindex',
                3 => 'Remove products with all variants unpublished on default channel',
                4 => 'Delete all products from index',
            ],
        );
        $this->executeAction($option, $app, $companyId);

        return;
    }

    protected function executeAction(int $option, Apps $app, ?string $companyId = null): void
    {
        $actions = [
            1 => function () use ($app, $companyId): void {
                $this->delete($app, $companyId);
            },
            2 => function () use ($app, $companyId): void {
                $this->reindex($app, $companyId);
            },
            3 => function () use ($app, $companyId): void {
                $this->removeProductsWithAllVariantsUnpublished($app, $companyId);
            },
            4 => function () use ($app, $companyId): void {
                $this->cleanAllInventoryFromIndex($app, $companyId);
            },
        ];

        if (isset($actions[$option])) {
            $actions[$option]();
        } else {
            $this->error('Invalid option selected.');
        }
    }

    public function reindex(Apps $app, ?string $companyId = null): void
    {
        $companyInfo = $companyId ? " for Company ID: {$companyId}" : '';
        $this->info('Reindex scout index for products App ' . $app->name . $companyInfo);

        $query = Products::fromApp($app)
                    ->where('is_published', 1)
                    ->where('is_deleted', 0);

        if ($companyId) {
            $query->where('companies_id', $companyId);
        }

        $products = $query->orderBy('id', 'DESC')->cursor();

        $i = 0;
        $j = 0;
        //need to iterate so custom index take effect
        foreach ($products as $product) {
            if ($product->shouldBeSearchable()) {
                $i++;
                $product->searchable();
            } else {
                $j++;
                $product->unsearchable();
            }
            usleep(100000); // 100ms delay between batches
        }
        $this->info('Total products to reindexed: ' . $i);
        $this->info('Total products to unindexed: ' . $j);
    }

    public function delete(Apps $app, ?string $companyId = null): void
    {
        $companyInfo = $companyId ? " for Company ID: {$companyId}" : '';
        $this->info('Cleaning up scout index for deleted products App ' . $app->name . $companyInfo);

        $query = Products::fromApp($app)
                    ->withTrashed()
                    ->where(function ($q) {
                        $q->where('is_published', 0)
                          ->orWhere('is_deleted', 1);
                    });

        if ($companyId) {
            $query->where('companies_id', $companyId);
        }

        $products = $query->cursor();

        $i = 0;
        foreach ($products as $product) {
            $product->unsearchable();
            $i++;
            usleep(100000); // 100ms delay between batches
        }

        $this->info('Total products to clean up: ' . $i);
    }

    public function removeProductsWithAllVariantsUnpublished(Apps $app, ?string $companyId = null): void
    {
        if ($companyId === null) {
            $this->error('Company ID is required for this operation.');

            return;
        }

        $companyInfo = " for Company ID: {$companyId}";
        $this->info('Removing products with all variants unpublished on default channel from scout index for App ' . $app->name . $companyInfo);

        // Get the company
        $company = Companies::getById((int) $companyId);

        // Get the default channel for the company using the default method
        $defaultChannel = Channels::getDefault($company, $app);

        if (! $defaultChannel) {
            $this->info('No default channel found for this company. Skipping operation.');

            return;
        }

        // Get products that are published but have no variants published on the default channel
        $query = Products::fromApp($app)
            ->where('is_published', 1)
            ->where('is_deleted', 0)
            ->where('companies_id', $companyId)
            ->whereHas('variants') // Ensure the product has variants
            ->whereDoesntHave('variants.variantChannels', function (Builder $q) use ($defaultChannel): void {
                $q->where('channels_id', $defaultChannel->getId())
                  ->where('is_published', 1)
                  ->where('is_deleted', 0);
            });

        $products = $query->cursor();

        $i = 0;
        foreach ($products as $product) {
            $product->unsearchable();
            $i++;
            usleep(100000); // 100ms delay between batches
        }

        $this->info('Total products with all variants unpublished on default channel removed from search: ' . $i);
    }

    public function cleanAllInventoryFromIndex(Apps $app, ?string $companyId = null): void
    {
        if ($companyId === null) {
            $this->error('Company ID is required for this operation.');

            return;
        }

        $companyInfo = $companyId ? " for Company ID: {$companyId}" : '';
        $this->info('Deleting all products from scout index for App ' . $app->name . $companyInfo);

        $query = Products::fromApp($app)->where('companies_id', $companyId);

        $products = $query->cursor();

        $i = 0;
        foreach ($products as $product) {
            $product->unsearchable();
            $i++;
            usleep(100000); // 100ms delay between batches
        }

        $this->info('Total products deleted from index: ' . $i);
    }
}
