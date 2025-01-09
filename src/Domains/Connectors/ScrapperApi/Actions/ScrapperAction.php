<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\ScrapperApi\Repositories\ScrapperRepository;
use Kanvas\Connectors\ScrapperApi\Services\ProductService;
use Kanvas\Connectors\Shopify\Actions\SyncProductWithShopifyAction;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Users\Models\Users;

use function Sentry\captureException;

use Throwable;

/**
 * Class ScrapperAction.
 */
class ScrapperAction
{
    public ?string $uuid = null;

    public function __construct(
        public AppInterface $app,
        public Users $user,
        public CompaniesBranches $companyBranch,
        protected Regions $region,
        public string $search,
        string $uuid = null
    ) {
        $this->uuid = $uuid ?? Str::uuid();
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
        foreach ($results as $i => $result) {
            $scrapperProducts++;

            try {
                if (preg_match('/(?:asin=|dp\/)([A-Z0-9]{10})/', $result['url'], $matches)) {
                    $asin = $matches[1];
                } else {
                    continue;
                }
                $product = $repository->getByAsin($asin);
                $product = array_merge($product, $result);
                if (empty($product['price']) && empty($product['original_price']['price'])) {
                    continue;
                }
                $mappedProduct = $service->mapProduct($product);
                if ($mappedProduct['price'] >= 230) {
                    continue;
                }
                $product = (
                    new ProductImporterAction(
                        ProductImporter::from($mappedProduct),
                        $this->companyBranch->company,
                        $this->user,
                        $this->region,
                        $this->app,
                        true
                    )
                )->execute();

                $syncProductWithShopify = new SyncProductWithShopifyAction($product);
                $syncProductWithShopify->execute();

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
