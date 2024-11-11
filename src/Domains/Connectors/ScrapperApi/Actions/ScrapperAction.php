<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
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

use Throwable;

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

    public function execute(): array
    {
        Log::info('Scrapper Started');
        $warehouse = $this->region->warehouses()->where('is_default', true)->first();

        $channels = Channels::getDefault($this->companyBranch->company);

        $repository = new ScrapperRepository($this->app);
        $results = $repository->getSearch($this->search);
        $service = new ProductService($channels, $warehouse);
        $scrapperProducts = 0;
        $importerProducts = 0;
        foreach ($results as $result) {
            $scrapperProducts++;

            try {
                $asin = $result['asin'];
                $productModel = Products::getBySlug($asin, $this->companyBranch->company);
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
                $importerProducts++;

                if (App::environment('local')) {
                    break;
                }
                if ($this->app->get('limit-product-scrapper')
                    && ($importerProducts > $this->app->get('limit-product-scrapper'))
                ) {
                    break;
                }
            } catch (Throwable $e) {
                Log::error($e->getMessage());
                captureException($e);
            }
        }

        return [
            'scrapperProducts' => $scrapperProducts,
            'importerProducts' => $importerProducts,
        ];
    }
}
