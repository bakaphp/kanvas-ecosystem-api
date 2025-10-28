<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapingDog\Jobs;

use App\GraphQL\Inventory\Types\ChannelInfoType;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Support\Facades\Cache;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\Gemini\Actions\TranslateToSpanishAction;
use Kanvas\Connectors\ScrapingDog\Actions\CreateProductAction;
use Kanvas\Connectors\ScrapingDog\Repositories\ScrapingDogRepository;
use Kanvas\Connectors\ScrapingDog\Services\ProductVariantService;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Services\VariantService;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

use function Sentry\captureException;

use Throwable;

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
        $maxAttempts = $this->receiver->configuration['max_attempts'] ?? 3;
        $minutesForUpdate = $this->receiver->configuration['minutes_for_update'] ?? 30;

        $app = $this->receiver->app;
        $request = $this->webhookRequest->payload;

        $key = 'update_v1_' . $request['sku'] . ':' . $this->receiver->app->getId();
        if (isset($request['forgot_cache'])) {
            Cache::forget($key);
        }

        return Cache::remember($key, $minutesForUpdate, function () use ($request, $maxAttempts) {
            $attempt = 0;

            while ($attempt < $maxAttempts) {
                $result = $this->updateVariant($request['sku']);

                if (! empty($result)) {
                    return $result;
                }

                $attempt++;
            }

            return [];
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
        $appId = $this->receiver->app->getId();
        $desiredSlug = $mappedProduct['slug'];

        $productModel = Products::where('apps_id', $appId)
            ->whereIn('slug', [$desiredSlug, $sku])
            ->orderByRaw('slug = ? DESC', [$desiredSlug])
            ->withTrashed()
            ->first();

        if ($productModel && $productModel->is_deleted) {
            $productModel->is_deleted = false;
            $productModel->save();
        }

        if ($productModel && $productModel->slug !== $desiredSlug) {
            $productModel->update(['slug' => $desiredSlug]);
        }

        try {
            if (! $productModel) {
                $dto = ProductDto::from([
                    'app' => $this->receiver->app,
                    'company' => $this->receiver->company,
                    'user' => $this->receiver->user,
                    'name' => $mappedProduct['name'],
                    'description' => $mappedProduct['description'],
                    'categories' => $mappedProduct['categories'],
                    'slug' => $mappedProduct['slug'],
                ]);
                $productModel = (new CreateProductAction(
                    $dto,
                    $this->receiver->user
                ))->execute();
            }

            // return $product;
            $variant = Variants::where('sku', $sku)
                ->where('apps_id', $this->receiver->app->getId())
                ->first();

            if (! $variant) {
                $newVariant = array_merge($mappedProduct, [
                    'sku' => $sku,
                    'slug' => $sku,
                    'source_id' => $sku,
                    'channels' => [[
                        'price' => $mappedProduct['price'],
                        'discounted_price' => $mappedProduct['discountPrice'],
                        'is_published' => true,
                        'warehouses_id' => $this->warehouse->getId(),
                        'channels_id' => $this->channel->getId(),
                    ]],
                ]);

                $variant = VariantService::createVariantsFromArray(
                    $productModel,
                    [$newVariant],
                    $this->receiver->user
                )[0];

                $variant->setTranslation('name', 'es', TranslateToSpanishAction::execute($variant->name) ?? $variant->name);
                $variant->setTranslation('description', 'es', TranslateToSpanishAction::execute($variant->description) ?? $variant->description);
                $variant->refresh()->load(['product', 'attributes', 'files', 'customFields']);
            } else {
                $variant->updatePriceInChannel(
                    $this->channel,
                    (float) $mappedProduct['price'],
                    (float) $mappedProduct['discountPrice']
                );
            }

            $customDict = collect($mappedProduct['custom_fields'])
                ->pluck('data', 'name')
                ->only(['product_information', 'feature_bullets'])
                ->all();

            $variant->set('product_information', $customDict['product_information']);
            $variant->set('feature_bullets', $customDict['feature_bullets']);

            if (! empty($mappedProduct['files'])) {
                $variant->deleteFiles();
                // $files = $mappedProduct['files'];
                foreach ($mappedProduct['files'] as $file) {
                    $variant->addFileFromUrl($file['url'], $file['name']);
                }
                $variant->refresh()->load(['product', 'attributes', 'files', 'customFields']);
                // UpdateFileSystemJob::dispatch($variant, $files);
            }

            $variantData = $variant->toArray();

            $variantData['channel'] = new ChannelInfoType()->price($variant, []);
            $variantData['product'] = Products::with(['files', 'categories'])
            ->where('id', $variant->products_id)
            ->withTrashed()
                ->first()
                ->toArray();

            $variants = Variants::with(['files', 'attributes'])
                ->where('products_id', $productModel->getId())
                ->where('apps_id', $this->receiver->app->getId())
                ->get();

            $variantData['translation'] = [
                'name' => $variant->getTranslation('name', 'es'),
                'description' => $variant->getTranslation('description', 'es'),
            ];

            $variantData['customization_options'] = $product['customization_options'];
            $variantData['product_information'] = $customDict['product_information'] ?? [];
            $variantData['feature_bullets'] = $customDict['feature_bullets'] ?? [];

            $variants = Variants::with(['files', 'attributes'])
                ->where('products_id', $productModel->getId())
                ->where('apps_id', $this->receiver->app->getId())
                ->get();

            return [
                'price' => $mappedProduct['price'],
                'discounted_price' => $mappedProduct['discountPrice'] ?? null,
                'variant' => $variantData,
                'variants' => $variants,
            ];
        } catch (Throwable $e) {
            captureException($e);
        }

        return [];
    }
}
