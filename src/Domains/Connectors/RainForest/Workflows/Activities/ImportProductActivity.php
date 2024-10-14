<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RainForest\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Str;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\RainForest\Repositories\ProductRepository;
use Kanvas\Inventory\Importer\Jobs\ProductImporterJob;
use Kanvas\Inventory\Regions\Models\Regions;
use Workflow\Activity;

class ImportProductActivity extends Activity
{
    public function execute(AppInterface $app, CompaniesBranches $companyBranch, Regions $region, string $search)
    {
        $warehouse = $region->warehouses()->where('is_default', true)->first();
        $productRepository = new ProductRepository($app, $warehouse);
        $products = $productRepository->getByTerm($search);
        $importProducts = [];
        foreach ($products as $product) {
            $productDetail = $productRepository->getByAsin($product['asin']);
            $importProducts[] = $productRepository->mapProduct($productDetail);
        }
        ProductImporterJob::dispatch(
            jobUuid: Str::uuid(),
            importer: $importProducts,
            branch: $companyBranch,
            user: auth()->user(),
            region: $region,
            app: $app
        );

        return $importProducts;
    }
}
