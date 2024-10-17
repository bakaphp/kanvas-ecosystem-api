<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RainForest\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Str;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\RainForest\Repositories\ProductRepository;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Importer\Jobs\ProductImporterJob;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Workflow\Activity;

class ImportProductActivity extends Activity
{
    public function execute(AppInterface $app, Users $users, CompaniesBranches $companyBranch, Regions $region, string $search)
    {
        try {
            $warehouse = $region->warehouses()->where('is_default', true)->first();
            $importProducts = [];
            $channels = Channels::getDefault($companyBranch->company);
            $channels->unPublishAllVariants();
            $productRepository = new ProductRepository($app, $warehouse, $channels);
            $products = $productRepository->getByTerm($search);
            $products = array_slice($products, 0, 10);
            foreach ($products as $product) {
                if (! key_exists('price', $product)) {
                    continue;
                }
                $productDetail = $productRepository->getByAsin($product['asin']);
                $productDetail['variants'] = []; // key_exists('variants', $product) ? $product['variants'] : [];
                $productDetail['price'] = $product['price'];
                $productDetail['categories'] = $product['categories'];
                $importProducts[] = $productRepository->mapProduct($productDetail);
            }
            ProductImporterJob::dispatch(
                jobUuid: Str::uuid(),
                importer: $importProducts,
                branch: $companyBranch,
                user: $users,
                region: $region,
                app: $app
            );

            return $importProducts;
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ];
        }
    }
}
