<?php
declare(strict_types=1);
namespace Kanvas\Connectors\ScrapperApi\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Connectors\Shopify\Client;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\ScrapperApi\Enums\ConfigEnum as ScrapperConfigEnum;
use Kanvas\Connectors\ScrapperApi\Events\ProductScrapperEvent;
use Kanvas\Connectors\ScrapperApi\Repositories\ScrapperRepository;
use Kanvas\Connectors\ScrapperApi\Services\ProductService;
use Kanvas\Connectors\Shopify\Actions\SyncProductWithShopifyAction;
use Kanvas\Users\Models\Users;
use Illuminate\Support\Facades\Log;
use PHPShopify\Exception\CurlException;
use Baka\Traits\KanvasJobsTrait;

class ScrapperProcessorAction
{
    use KanvasJobsTrait;

    public function __construct(
        public AppInterface $app,
        public Users $user,
        public CompaniesBranches $companyBranch,
        protected Regions $region,
        public array $results,
        public ?string $uuid = null
    ) {
        $this->uuid = $uuid;

    }

    public function execute()
    {
        $this->overwriteAppService(app: $this->app);
        $warehouse = $this->region->warehouses()->where('is_default', true)->first();
        $channels = Channels::getDefault($this->companyBranch->company);
        $repository = new ScrapperRepository($this->app);
        $service = new ProductService($channels, $warehouse);
        foreach ($this->results as $i => $result) {
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
                $originalName = $product['name'];
                $mappedProduct = $service->mapProduct($product);

                if ($mappedProduct['price'] >= 230) {
                    continue;
                }
                try {
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

                } catch (\Throwable $e) {
                    Log::error($e->getMessage());
                    Log::debug($e->getTraceAsString());
                    continue;
                }
                Log::info(message: "Product name: " . $result['name']);

                $syncProductWithShopify = new SyncProductWithShopifyAction($product);
                try {
                    $response = $syncProductWithShopify->execute();
                } catch (CurlException $e) {
                    continue;
                }
                Log::info(message: "Product synced with Shopify");
                $this->setCustomFieldAmazonPrice(product: $product);

                if ($this->uuid) {
                    // ProductScrapperEvent::dispatch(
                    //     $this->app,
                    //     $this->uuid,
                    //     $product,
                    //     $product->getShopifyId($this->region),
                    //     $response[0]
                    // );
                }
                $shopifyData = [
                    'shopify_id' => $product->getShopifyId($this->region),
                    'shopify_product_id' => $response[0]['id'],
                    'shopify_variant_id' => $response[1]['id'],
                    'image' => $response[0]['image'],
                    'price' => $response[0]['variants'][0]['price'],
                    'discounted_price' => 0,
                    'title' => mb_substr($response[0]['title'], 0, 255),
                    'images' => $response[0]['images'],
                    'en_title' => $originalName,
                ];
                $product->set('shopify_data', $shopifyData);
            } catch (\Throwable $e) {
                continue;
            }
        }
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
