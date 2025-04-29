<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Actions;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Support\Facades\Log;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\ScrapperApi\Enums\ConfigEnum as ScrapperConfigEnum;
use Kanvas\Connectors\ScrapperApi\Events\ProductScrapperEvent;
use Kanvas\Connectors\ScrapperApi\Repositories\ScrapperRepository;
use Kanvas\Connectors\ScrapperApi\Services\ProductVariantService;
use Kanvas\Connectors\Shopify\Actions\CreateProductGraphql;
use Kanvas\Connectors\Shopify\Actions\CreateProductVariantGraphql;
use Kanvas\Connectors\Shopify\Actions\ImagesGraphql;
use Kanvas\Connectors\Shopify\Actions\UpdateProductGraphql;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Users\Models\Users;

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

    public function execute(): array
    {
        $productList = [];
        $this->overwriteAppService(app: $this->app);
        $warehouse = $this->region->warehouses()->where('is_default', true)->first();
        $channels = Channels::getDefault($this->companyBranch->company);
        $repository = new ScrapperRepository($this->app);
        $service = new ProductVariantService($channels, $warehouse, $this->user);
        foreach ($this->results as $i => $result) {
            try {
                $product = $repository->getByAsin($result['asin']);
                $product = array_merge($product, $result);
                if (empty($product['price']) && empty($product['original_price']['price'])) {
                    continue;
                }
                $originalName = $product['name'];
                $mappedProduct = $service->mapProduct($product);
                if (isset($product['customization_options'])) {
                    $mappedProduct['variants'] = $service->mapVariant($product);
                } else {
                    $mappedProduct['variants'] = $mappedProduct;
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
                } catch (\Exception $e) {
                    Log::error($e->getMessage());
                    Log::debug($e->getTraceAsString());

                    continue;
                }

                if ($this->app->get('ScrapperApi-Index-Shopify')) {
                    $metafields = $this->getMetaFields(product: $product);
                    $shopifyProductId = $product->getShopifyId($warehouse->regions);
                    if (! $shopifyProductId) {
                        $shopifyProduct = (new CreateProductGraphql(
                            $this->app,
                            $this->companyBranch,
                            $warehouse,
                            $product,
                            $metafields
                        ))->execute();
                    } else {
                        $shopifyProduct = (new UpdateProductGraphql(
                            $this->app,
                            $this->companyBranch,
                            $warehouse,
                            $product,
                            $metafields
                        ))->execute();
                    }
                    $variants = (new CreateProductVariantGraphql(
                        $this->app,
                        $this->companyBranch,
                        $warehouse,
                        $product
                    ))->execute();
                    $images = (new ImagesGraphql(
                        $this->app,
                        $this->companyBranch,
                        $warehouse,
                        $product
                    ))->execute();

                    (new SaveCustomFieldDataAction(
                        $warehouse,
                        $product,
                        $this->region,
                        $originalName
                    ))->execute();
                }

                if ($this->uuid) {
                    ProductScrapperEvent::dispatch(
                        $this->app,
                        $this->uuid,
                        $product,
                        $product->variants()->first()->getPrice($warehouse),
                        $product->getShopifyId($this->region),
                    );
                }
            } catch (\Throwable $e) {
                Log::error($e->getMessage());
                Log::debug($e->getTraceAsString());

                continue;
            }
            $productList[] = $product;
        }

        return $productList;
    }

    public function getMetaFields(Products $product): array
    {
        $attribute = $product->attributes()->where('name', operator: ScrapperConfigEnum::AMAZON_PRICE->value)->first();
        $metafieldData = [
            [
                'namespace' => 'custom',
                'key' => 'amazon_price',
                'value' => json_encode(['amount' => $attribute->value, 'currency_code' => 'USD']),
                'type' => 'money',
            ],
        ];

        return $metafieldData;
    }
}
