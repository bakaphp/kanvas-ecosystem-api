<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\ScrapperApi\Enums\ConfigEnum as ScrapperConfigEnum;
use Kanvas\Connectors\ScrapperApi\Events\ProductScrapperEvent;
use Kanvas\Connectors\ScrapperApi\Repositories\ScrapperRepository;
use Kanvas\Connectors\ScrapperApi\Services\ProductService;
use Kanvas\Connectors\Shopify\Actions\SyncProductWithShopifyAction;
use Kanvas\Connectors\Shopify\Client;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
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

                $this->setCustomFieldAmazonPrice($product);
                $importerProducts++;

                if ($this->uuid) {
                    ProductScrapperEvent::dispatch(
                        $this->app,
                        $this->uuid,
                        $product,
                        $product->getShopifyId($this->region)
                    );
                }

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

    public function setCustomFieldAmazonPrice(Products $product): void
    {
        $sdk = Client::getInstance($this->app, $this->companyBranch->company, $this->region);
        $shopifyProductId = $product->getShopifyId($this->region);
        $attribute = $product->attributes()->where('name', ScrapperConfigEnum::AMAZON_PRICE->value)->first();
        $metafieldData = [
            'namespace' => 'custom',
            'key' => 'amazon_price',
            'value' => json_encode(['amount' => $attribute->value, 'currency_code' => 'USD']),
            'type' => 'money',
        ];

        $sdk->Product($shopifyProductId)->Metafield->post($metafieldData);
    }
}
