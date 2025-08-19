<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapingDog\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\ScrapingDog\Enums\ConfigEnum;
use Kanvas\Connectors\ScrapingDog\Repositories\ScrapingDogRepository;
use Kanvas\Connectors\ScrapingDog\Services\ProductVariantService;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Services\VariantService;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

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
        $variant = Variants::where('sku', $request['sku'])
        ->where('apps_id', $app->getId())
            ->firstOrFail();
        $minutesForUpdate = $this->receiver->configuration['minutes_for_update'] ?? 30;

        $key = ConfigEnum::VARIANT_PRICE_UPDATE->value . ':' . $variant->getId();

        return Cache::remember($key, $minutesForUpdate, function () use ($variant) {
            return $this->updateVariant($variant);
        });
    }

    protected function updateVariant(Variants $variant): array
    {
        $product = new ScrapingDogRepository($this->receiver->app)->getByAsin($variant->sku);
        $productVariantService = new ProductVariantService(
            $this->channel,
            $this->warehouse,
            $this->receiver->user,
        );
        $mappedProduct = $productVariantService->mapProduct($product);
        $productModel = $variant->product;
        $productModel->name = $mappedProduct['name'];
        $productModel->description = $mappedProduct['description'] ?? '';
        $productModel->slug = $mappedProduct['slug'];
        $productModel->save();
        $variants = $productVariantService->mapVariant($product);
        $variantsModels = VariantService::createVariantsFromArray($productModel, $variants, auth()->user());
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
        $variant->set(ConfigEnum::VARIANT_PRICE_UPDATE->value, true);
        $variant->set(ConfigEnum::VARIANT_PRICE_DATE_UPDATE->value, Carbon::now());

        return [
            'price' => $mappedProduct['price'],
            'discounted_price' => $mappedProduct['discountPrice'] ?? null,
            'variants' => $variantsModels,
            'product' => $productModel->toArray(),
        ];
    }
}
