<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Str;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\ScrapperApi\Repositories\ScrapperRepository;
use Kanvas\Connectors\ScrapperApi\Services\ProductService;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Importer\Jobs\ProductImporterJob;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Users\Models\Users;

use function Sentry\captureException;

/**
 * Class ScrapperAction.
 */
class ScrapperAction
{
    public function __construct(
        public AppInterface $app,
        public Users $user,
        public CompaniesBranches $companyBranch,
        protected Regions $region,
        public string $search
    ) {
    }

    public function execute(): void
    {
        $warehouse = $this->region->warehouses()->where('is_default', true)->first();

        $channels = Channels::getDefault($this->companyBranch->company);

        $repository = new ScrapperRepository($this->app);
        $results = $repository->getSearch($this->search);
        $service = new ProductService($channels, $warehouse);
        foreach ($results as $result) {
            try {
                $asin = $result['asin'];
                $productModel = Products::getBySlug($asin, $this->companyBranch->company);
                if ($productModel && $productModel->updated_at->addDays(3)->isPast()) {
                    continue;
                }
                $product = $repository->getByAsin($asin);
                $product = array_merge($product, $result);
                $mappedProduct = $service->mapProduct($product);

                ProductImporterJob::dispatch(
                    jobUuid: Str::uuid(),
                    importer: [$mappedProduct],
                    branch: $this->companyBranch,
                    user: $this->user,
                    region: $this->region,
                    app: $this->app
                );
            } catch (\Throwable $e) {
                captureException($e);
            }
            // Do something with the product
        }
    }
}
