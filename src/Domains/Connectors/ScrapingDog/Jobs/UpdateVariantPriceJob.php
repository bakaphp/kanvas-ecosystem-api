<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapingDog\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Support\Facades\Cache;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\ScrapingDog\Repositories\ScrapingDogRepository;
use Kanvas\Connectors\ScrapingDog\Services\ProductVariantService;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Services\VariantService;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

use function Sentry\captureException;

class UpdateVariantPriceJob extends ProcessWebhookJob
{
    use KanvasJobsTrait;
    public Channels $channel;
    public Warehouses $warehouse;
    public CompaniesBranches $companiesBranches;

    #[Override]
    public function execute(): array
    {
        $this->channel = Channels::getById($this->receiver->configuration['channel_id']);
        $this->warehouse = Warehouses::getById($this->receiver->configuration['warehouse_id']);
        $app = $this->receiver->app;
        $this->overwriteAppService($app);
        $request = $this->webhookRequest->payload;
        $minutesForUpdate = $this->receiver->configuration['minutes_for_update'] ?? 30;
        $key = $request['sku'] . ':' . $this->receiver->app->getId();

        return Cache::remember($key, $minutesForUpdate, function () use ($request) {
            return $this->updateVariant($request['sku']);
        });
    }

    protected function updateVariant(string $sku): array
    {
        $repository = new ScrapingDogRepository($this->receiver->app);
        $product = $repository->getByAsin($sku);
        $productVariantService = new ProductVariantService(
            $this->channel,
            $this->warehouse,
            $this->receiver->user,
        );
        $mappedProduct = $productVariantService->mapProduct($product);
        $productModel = Products::where('slug', $mappedProduct['slug'])
                        ->where('apps_id', $this->receiver->app->getId())
                        ->first();

        try {
            $mappedProduct['variants'] = $productVariantService->mapVariant($product);
            if (! $productModel) {
                $productModel = (
                        new ProductImporterAction(
                            ProductImporter::from($mappedProduct),
                            $this->receiver->company,
                            auth()->user(),
                            Regions::getById($this->receiver->configuration['region_id']),
                            $this->receiver->app,
                            true
                        )
                )->execute();
                $productModel->searchable();
            } elseif ($productModel->variants->count() < count($mappedProduct['variants'])) {
                VariantService::createVariantsFromArray(
                    $productModel,
                    $mappedProduct['variants'],
                    auth()->user()
                );
            }
            // @todo: remove this, cause redundant
            $variant = Variants::where('sku', $sku)
                ->where('apps_id', $this->receiver->app->getId())
                ->firstOrFail();
            $variant->updatePriceInChannel(
                $this->channel,
                (float) $mappedProduct['price'],
                (float) $mappedProduct['discountPrice']
            );
            if ($mappedProduct['files']) {
                $variant->deleteFiles();
                foreach ($mappedProduct['files'] as $file) {
                    $variant->addFileFromUrl($file['url'], $file['name']);
                }
            }

            return [
                'price' => $mappedProduct['price'],
                'discounted_price' => $mappedProduct['discountPrice'] ?? null,
                'variants' => $productModel->variants->toArray(),
                'product' => $productModel->toArray(),
            ];
        } catch (\Throwable $e) {
            captureException($e);
        }

        return [];
    }
}
