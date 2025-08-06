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
        $variant = Variants::where('sku', $request['sku'])
        ->where('apps_id', $app->getId())
            ->firstOrFail();
        $response = [
            'message' => 'Variant price updated',
        ];
        if (! $variant->get(ConfigEnum::VARIANT_PRICE_UPDATE->value)) {
            $data = $this->updatePriceVariant($variant);
            $response['price'] = $data['price'];
            $response['discounted_price'] = $data['discounted_price'] ?? null;
        } elseif (Carbon::parse($variant->get(ConfigEnum::VARIANT_PRICE_DATE_UPDATE->value))->isLastWeek()) {
            $data = $this->updatePriceVariant($variant);
            $response['price'] = $data['price'];
            $response['discounted_price'] = $data['discounted_price'] ?? null;
        }

        return $response;
    }

    protected function updatePriceVariant(Variants $variant): array
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

        return [
            'price' => $mappedProduct['price'],
            'discounted_price' => $mappedProduct['discountPrice'] ?? null,
        ];
    }
}
