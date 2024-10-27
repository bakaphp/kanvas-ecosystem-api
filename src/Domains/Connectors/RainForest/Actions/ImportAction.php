<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RainForest\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Str;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\RainForest\Repositories\ProductRepository;
use Kanvas\Connectors\RainForest\Services\ProductService;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Importer\Jobs\ProductImporterJob;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Users\Models\Users;

class ImportAction
{
    public function __construct(
        protected AppInterface $app,
        protected Users $users,
        protected CompaniesBranches $companyBranch,
        protected Regions $region,
        protected string $search
    ) {
    }

    public function execute(): array
    {
        $companyBranch = $this->companyBranch;
        $users = $this->users;
        $region = $this->region;
        $search = $this->search;
        $warehouse = $region->warehouses()->where('is_default', true)->first();
        $importProducts = [];
        $channels = Channels::getDefault($companyBranch->company);
        $channels->unPublishAllVariants();
        $productRepository = new ProductRepository($this->app, $warehouse, $channels);
        $productService = new ProductService($channels, $warehouse);
        $products = $productRepository->getByTerm($search);
        $products = array_slice($products, 0, length: 10);
        foreach ($products as $product) {
            if (! key_exists('price', $product)) {
                continue;
            }
            $productDetail = $productRepository->getByAsin($product['asin']);
            $productDetail['variants'] = []; // key_exists('variants', $product) ? $product['variants'] : [];
            $productDetail['price'] = $product['price'];
            $productDetail['categories'] = $product['categories'];

            $importProducts[] = $productService->mapProduct($productDetail);
        }
        ProductImporterJob::dispatch(
            jobUuid: Str::uuid(),
            importer: $importProducts,
            branch: $companyBranch,
            user: $users,
            region: $region,
            app: $this->app
        );

        return $importProducts;
    }
}
