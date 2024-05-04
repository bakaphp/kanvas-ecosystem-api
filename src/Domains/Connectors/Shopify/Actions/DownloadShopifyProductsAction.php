<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Actions;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\Shopify\Client;
use Kanvas\Inventory\Importer\Jobs\ProductImporterJob;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\Users;

class DownloadShopifyProductsAction
{
    protected array $files;

    public function __construct(
        protected Apps $app,
        protected Warehouses $warehouses,
        protected CompaniesBranches $branch,
        protected Users $user
    ) {
    }

    public function execute(array $params = [])
    {
        $firstPage = null;
        $shopify = Client::getInstance(
            $this->app,
            $this->warehouses->company,
            $this->warehouses->region
        );
        $totalRecords = 0;
        $productsToImport = [];

        $shopifyP = $shopify->Product();

        do {
            if (! $firstPage && $shopifyP->getNextPageParams()) {
                // Get next page parameters without 'status' filter
                $params = $shopifyP->getNextPageParams();
            }

            // Get products based on the current set of parameters
            $currentShopifyProductPage = $shopifyP->get($params);

            foreach ($currentShopifyProductPage as $shopifyProduct) {
                $name = $shopifyProduct['title'];
                $description = $shopifyProduct['body_html'];
                $slug = $shopifyProduct['handle'];
                $productId = $shopifyProduct['id'];

                $files = ! empty($shopifyProduct['images']) ? $shopifyProduct['images'] : [];
                $this->mapFiles($files);

                //attributes
                $productTags = ! empty($shopifyProduct['tags']) ? explode($shopifyProduct['tags'], ',') : [];
                $productAttributes = [];

                $productsToImport[] = [
                    'name' => $name,
                    'description' => $description,
                    'slug' => $slug,
                    'sku' => (string) $productId,
                    'regionId' => $this->warehouses->region->id,
                    'price' => 0,
                    'discountPrice' => 0,
                    'quantity' => 1,
                    'isPublished' => true,
                    'files' => $this->files['files'] ?? [],
                    'categories' => [
                        [
                            'name' => ! empty($shopifyProduct['product_type']) ? $shopifyProduct['product_type'] : 'Uncategorized',
                            'code' => ! empty($shopifyProduct['product_type']) ? $shopifyProduct['product_type'] : 'Uncategorized',
                            'is_published' => true,
                            'position' => 1,
                        ],
                    ],
                    'attributes' => [],
                    'variants' => $this->mapVariants($shopifyProduct['variants'], $shopifyProduct['options']),
                ];

                $totalRecords++;
            }

            $firstPage = false; // After first fetch, do not reset to initial parameters
        } while ($shopifyP->getNextPageParams());

        $jobUuid = Str::uuid()->toString();
        ProductImporterJob::dispatch(
            $jobUuid,
            $productsToImport,
            $this->branch,
            $this->user,
            $this->warehouses->region,
            $this->app
        );
    }

    protected function mapVariants(array $variants, array $shopifyProductOptions): array
    {
        foreach ($variants as $variant) {
            $variantName = $variant['title'];
            $options = array_filter([$variant['option1'], $variant['option2'], $variant['option3']]);
            $variantName .= ' ' . implode(' ', $options);

            $productVariants[] = [
                'name' => $variantName,
                'description' => null,
                'sku' => ! empty($variant['sku']) ? (string) $variant['sku'] : (string) $variant['id'],
                'price' => (float) $variant['price'],
                'discountPrice' => (float) $variant['compare_at_price'],
                'is_published' => true,
                'slug' => (string) $variant['id'],
                'files' => $this->files['filesSystemVariantImages'][$variant['id']] ?? [],
                'warehouse' => [
                    'id' => $this->warehouses->id,
                    'price' => (float) $variant['price'],
                    'quantity' => $variant['inventory_quantity'],
                    'sku' => (string) ($variant['sku'] ?? $variant['id']),
                    'is_new' => true,
                ],
                'attributes' => [
                    [
                        'name' => $shopifyProductOptions[0]['name'],
                        'value' => $variant['option1'],
                    ],
                ],
            ];
        }

        return $productVariants;
    }

    protected function mapFiles(array $files): void
    {
        $fileSystem = [];
        $filesSystemVariantImages = [];

        $i = 0;
        foreach ($files as $file) {
            //if file is a url
            if (! filter_var($file['src'], FILTER_VALIDATE_URL)) {
                continue;
            }

            $path = parse_url($file['src'], PHP_URL_PATH);
            $filename = basename($path);

            $shopifyImage = [
                'url' => $file['src'],
                'name' => $filename,
            ];
            $fileSystem[] = $shopifyImage;
            if (! empty($file['variant_ids'])) {
                $filesSystemVariantImages[$file['variant_ids'][0]] = $shopifyImage;
            }

            $i++;
        }

        $this->files = [
            'files' => $fileSystem,
            'filesSystemVariantImages' => $filesSystemVariantImages,
        ];
    }
}
