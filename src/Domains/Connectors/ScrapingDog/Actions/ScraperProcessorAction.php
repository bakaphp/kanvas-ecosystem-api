<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapingDog\Actions;

use Kanvas\Connectors\ScrapingDog\Repositories\ScrapingDogRepository;
use Kanvas\Connectors\ScrapingDog\Services\ProductVariantService;
use Kanvas\Connectors\ScrapperApi\Actions\ScrapperProcessorAction as ScrapperApiProcessorAction;
use Kanvas\Connectors\ScrapperApi\Events\ProductScrapperEvent;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;

use function Sentry\captureException;

class ScraperProcessorAction extends ScrapperApiProcessorAction
{
    public function execute(): array
    {
        $productList = [];
        $this->overwriteAppService(app: $this->app);
        $warehouse = $this->region->warehouses()->where('is_default', true)->first();
        $channels = Channels::getDefault($this->companyBranch->company);
        $repository = new ScrapingDogRepository($this->app);
        $service = new ProductVariantService($channels, $warehouse, $this->user);
        foreach ($this->results as $i => $result) {
            try {
                if (! isset($result['asin']) || empty($result['asin'])) {
                    continue;
                }
                $product = $repository->getByAsin($result['asin']);
                if (empty($product)) {
                    continue;
                }
                $mappedProduct = $service->mapProduct($product);
                $mappedProduct['variants'] = $service->mapVariant($product);
                if (empty($mappedProduct) || $mappedProduct['price'] == 0) {
                    continue;
                }
                if (empty($mappedProduct['variants'])) {
                    $variant = $mappedProduct;
                    $mappedProduct['variants'] = [$variant];
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
                $product->searchable();
                if ($this->uuid) {
                    ProductScrapperEvent::dispatch(
                        $this->app,
                        $this->uuid,
                        $product->toArray(),
                        $product->variants()->first()->getPrice($warehouse),
                        $this->searchText
                    );
                }

                $productList[] = $product;
            } catch (\Throwable $e) {
                captureException($e);
            }
        }

        return $productList;
    }
}
