<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapingDog\Actions;

use Kanvas\Connectors\ScrapingDog\Enums\ConfigEnum as ScrapingDogConfigEnum;
use Kanvas\Connectors\ScrapingDog\Repositories\ScrapingDogRepository;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;

use function Sentry\captureException;

class ScraperProductAction extends ScraperAction
{
    public function execute(): array
    {
        $repository = new ScrapingDogRepository($this->app);
        $results = $repository->getSearch($this->search)['results'] ?? [];

        $app = $this->app;
        $user = $this->user;
        $companyBranch = $this->companyBranch;
        $region = $this->region;
        $products = [];
        foreach ($results as $result) {
            $data = $this->mapProduct($result);

            try {
                $product = (
                    new ProductImporterAction(
                        ProductImporter::from($data),
                        $this->companyBranch->company,
                        $this->user,
                        $this->region,
                        $this->app,
                        true
                    )
                )->execute();
                config()->set('scout.queue', false);
                $product->searchable();
                $products[] = $product['id'];
            } catch (\Throwable $e) {
                captureException($e);

                continue;
            }
        }

        return $products;
    }

    public function mapProduct(array $product): array
    {
        $warehouse = $this->region->warehouses()->where('is_default', true)->first();
        $channels = Channels::getDefault($this->companyBranch->company);
        $price = str_replace(['$', ','], '', $product['price'] ?? '0');

        return [
            'name' => $product['title'],
            'description' => $product['description'] ?? '',
            'slug' => $product['asin'],
            'sku' => $product['asin'],
            'source' => 'amazon',
            'source_id' => $product['parent_asin'] ?? $product['asin'],
            'files' => [
                [
                    'url' => $product['image'],
                    'name' => 'main_image',
                ],
            ],
            'isPublished' => true,
            'categories' => [],
            'variants' => [
                [
                    'name' => $product['title'],
                    'sku' => $product['asin'],
                    'slug' => $product['asin'],
                    'source_id' => $product['asin'],
                    'files' => [
                        [
                            'url' => $product['image'],
                            'name' => 'main_image',
                        ],
                    ],
                    'channels' => [
                        [
                            'price' => $price,
                            'discounted_price' => $price,
                            'is_published' => true,
                            'warehouses_id' => $warehouse->getId(),
                            'channels_id' => $channels->getId(),
                        ],
                    ],
                    'warehouses' => [
                        [
                            'name' => $warehouse->name,
                            'sku' => $product['asin'],
                            'quantity' => $channels->app->get(ScrapingDogConfigEnum::DEFAULT_QUANTITY->value) ?? 1,
                            'price' => $price,
                            'is_new' => true,
                            'channel' => $channels->name,
                            'id' => $warehouse->getId(),
                        ],
                    ],
                ],
            ],
        ];
    }
}
