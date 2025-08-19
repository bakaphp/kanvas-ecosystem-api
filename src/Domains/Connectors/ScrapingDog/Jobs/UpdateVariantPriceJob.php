<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapingDog\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Carbon\Carbon;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\ScrapingDog\Actions\ScraperProcessorAction;
use Kanvas\Connectors\ScrapingDog\Enums\ConfigEnum;
use Kanvas\Connectors\ScrapingDog\Repositories\ScrapingDogRepository;
use Kanvas\Connectors\ScrapingDog\Services\ProductService;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants;
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
        $response = [
            'message' => 'Variant price updated',
        ];
        $minutesForUpdate = $this->receiver->configuration['minutes_for_update'] ?? 30;
        if (! $variant->get(ConfigEnum::VARIANT_PRICE_UPDATE->value)) {
            $data = $this->updateVariant($variant);
            $response['price'] = $data['price'];
            $response['discounted_price'] = $data['discounted_price'] ?? null;
        } elseif (Carbon::parse($variant->get(ConfigEnum::VARIANT_PRICE_DATE_UPDATE->value))->diffInMinutes() >= $minutesForUpdate) {
            $data = $this->updateVariant($variant);
            $response['price'] = $data['price'];
            $response['discounted_price'] = $data['discounted_price'] ?? null;
        }

        return $response;
    }

    protected function updateVariant(Variants $variant): array
    {
        $product = new ScrapingDogRepository($this->receiver->app)->getByAsin($variant->sku);
        $mappedProduct = new ProductService(
            $this->channel,
            $this->warehouse,
            $this->receiver->user,
        )->mapProduct($product);
        $productModel = $variant->product;
        $productModel->name = $mappedProduct['name'];
        $productModel->description = $mappedProduct['description'] ?? '';
        $productModel->slug = $mappedProduct['slug'];
        $productModel->save();
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

        if (! $variant->product->get(ConfigEnum::VARIANT_DOWNLOAD->value)) {
            $result = [
                    [
                        'asin' => $productModel->slug,
                    ],
                ];
            $companyBranch = CompaniesBranches::getById($this->receiver->configuration['company_branch_id']);
            $action = (new ScraperProcessorAction(
                $variant->app,
                $this->receiver->user,
                $companyBranch,
                Regions::getById($this->receiver->configuration['region_id']),
                $result
            ));
            if ($action->execute()) {
                $variant->product->set(ConfigEnum::VARIANT_DOWNLOAD->value, 1);
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
