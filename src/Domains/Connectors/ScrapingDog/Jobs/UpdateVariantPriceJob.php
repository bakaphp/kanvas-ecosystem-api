<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapingDog\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Carbon\Carbon;
use Kanvas\Connectors\ScrapingDog\Enums\ConfigEnum;
use Kanvas\Connectors\ScrapingDog\Repositories\ScrapingDogRepository;
use Kanvas\Connectors\ScrapingDog\Services\ProductService;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class UpdateVariantPriceJob extends ProcessWebhookJob
{
    use KanvasJobsTrait;

    #[Override]
    public function execute(): array
    {
        $app = $this->receiver->app;
        $this->overwriteAppService($app);
        $request = $this->webhookRequest->payload;
        $variant = Variants::where('slug', $request['slug'])
        ->where('apps_id', $app->getId())
            ->firstOrFail();
        if (! $variant->get(ConfigEnum::VARIANT_PRICE_UPDATE->value)) {
            $this->updatePriceVariant($variant);
        } elseif (Carbon::parse($variant->get(ConfigEnum::VARIANT_PRICE_DATE_UPDATE->value))->isLastWeek()) {
            $this->updatePriceVariant($variant);
        }

        return [
            'message' => 'Variant price updated',
        ];
    }

    protected function updatePriceVariant(Variants $variant)
    {
        $product = new ScrapingDogRepository($this->receiver->app)->getByAsin($variant->sku);
        $channels = Channels::getById($this->receiver->configuration['channel_id']);
        $warehouse = Warehouses::getById($this->receiver->configuration['warehouse_id']);
        $mappedProduct = new ProductService(
            $channels,
            $warehouse,
            $this->receiver->user,
        )->mapProduct($product);
        $variant->updatePriceInChannel(
            $channels,
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
    }
}
