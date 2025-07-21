<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Actions;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Support\Facades\Log;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\Gemini\Actions\TranslateToSpanishAction;
use Kanvas\Connectors\ScrapperApi\Enums\ConfigEnum as ScrapperConfigEnum;
use Kanvas\Connectors\ScrapperApi\Events\ProductScrapperEvent;
use Kanvas\Connectors\ScrapperApi\Repositories\ScrapperRepository;
use Kanvas\Connectors\ScrapperApi\Services\ProductVariantService;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Users\Models\Users;

use function Sentry\captureException;

class ScrapperProcessorAction
{
    use KanvasJobsTrait;

    public function __construct(
        public AppInterface $app,
        public Users $user,
        public CompaniesBranches $companyBranch,
        protected Regions $region,
        public array $results,
        public ?string $uuid = null,
        public ?string $searchText = null,
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

        $productVariantService = new ProductVariantService(
            $channels,
            $warehouse,
            $this->user,
        );
        foreach ($this->results as $i => $result) {
            try {
                if (! isset($result['asin']) || empty($result['asin'])) {
                    Log::warning('No ASIN found for product', ['result' => $result]);

                    continue;
                }
                Products::withTrashed()
                    ->where('slug', $result['asin'])
                    ->update([
                        'is_deleted' => 0,
                    ]);
                $product = $repository->getByAsin($result['asin']);
                $product = array_merge($product, $result);
                if (empty($product['price']) && empty($product['original_price']['price'])) {
                    continue;
                }
                $originalName = $product['name'];
                $originalDescription = $service->getDescription($product);

                $mappedProduct = $service->mapProduct($product);
                $mappedProduct['variants'] = $productVariantService->mapVariant($product);

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
                    $product->searchable();
                } catch (\Exception $e) {
                    captureException($e);

                    continue;
                }

                if ($this->uuid) {
                    ProductScrapperEvent::dispatch(
                        $this->app,
                        $this->uuid,
                        $product,
                        $product->variants()->first()->getPrice($warehouse),
                        $product->getShopifyId($this->region),
                        $this->searchText
                    );
                }
            } catch (\Throwable $e) {
                Log::error($e->getMessage());
                Log::debug($e->getTraceAsString());

                continue;
            }
            $product->setTranslation('name', 'es', TranslateToSpanishAction::execute($originalName) ?? $originalName);
            $product->setTranslation('description', 'es', TranslateToSpanishAction::execute($originalDescription) ?? $originalDescription);
            $product->save();
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
