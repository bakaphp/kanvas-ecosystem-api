<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Recombee;

use Baka\Traits\KanvasJobsTrait;
use Exception;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Recombee\Services\RecombeeProductIndexService;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;

class IndexProductRecombeeCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:recombee-index-products 
                            {app_id : The App ID to index products for}
                            {--product_type_id= : Optional Product Type ID to filter products}
                            {--limit=0 : Limit number of products to index (0 = all)}';

    protected $description = 'Index products to Recombee for recommendations';

    public function handle(): void
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $productTypeId = $this->option('product_type_id');
        $limit = (int) $this->option('limit');

        // Build query
        $query = Products::fromApp($app)
            ->where('is_published', true)
            ->orderBy('id', 'desc');

        // Filter by product type if specified
        if ($productTypeId !== null && $productTypeId !== '') {
            $productType = ProductsTypes::getById((int) $productTypeId, $app);
            $query->where('products_types_id', $productType->getId());
            $this->info('Filtering by Product Type: ' . (string) $productType->name);
        }

        // Apply limit if specified
        if ($limit > 0) {
            $query->limit($limit);
            $this->info('Limiting to ' . $limit . ' products');
        }

        $cursor = $query->cursor();
        $totalProducts = $query->count();

        $this->output->progressStart($totalProducts);
        $productIndex = new RecombeeProductIndexService($app);

        // Create product catalog database schema
        try {
            $productIndex->createProductCatalogDatabase();
            $this->info('Product catalog database schema created/verified');
        } catch (Exception $e) {
            $this->error('Error creating product catalog database: ' . $e->getMessage());
        }

        $indexed = 0;
        $failed = 0;

        foreach ($cursor as $product) {
            try {
                $productIndex->indexProduct($product);

                $this->info('Product ID: ' . (string) $product->getId() . ' (' . (string) $product->name . ') indexed');
                $indexed++;
                $this->output->progressAdvance();
            } catch (Exception $e) {
                $this->error('Error indexing product ID: ' . (string) $product->getId() . ' - ' . $e->getMessage());
                $failed++;
            }
        }

        $this->output->progressFinish();

        // Summary
        $this->line('');
        $this->line('=== Indexing Summary ===');
        $this->info('Successfully indexed: ' . $indexed);
        $this->error('Failed: ' . $failed);
        $this->line('Total: ' . $totalProducts);
    }
}
