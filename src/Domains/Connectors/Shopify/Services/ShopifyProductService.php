<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

class ShopifyProductService
{
    protected array $files;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected Regions $region,
        protected string|int $productId,
        protected ?UserInterface $user = null,
        protected ?Warehouses $warehouses = null,
        protected ?Channels $channel = null
    ) {
        $this->user = $user ?? $this->company->user;
        $this->warehouses = $warehouses ?? Warehouses::fromCompany($this->company)->where('is_default', 1)->where('regions_id', $this->region->id)->firstOrFail();
        $this->channel = $channel ?? Channels::fromCompany($this->company)->where('is_default', 1)->firstOrFail();
    }

    public function mapProductForImport(array $shopifyProduct): array
    {
        $name = $shopifyProduct['title'];
        $description = $shopifyProduct['body_html'];
        $slug = $shopifyProduct['handle'];
        $productId = $shopifyProduct['id'];

        $files = ! empty($shopifyProduct['images']) ? $shopifyProduct['images'] : [];
        $this->mapFilesForImport($files);

        //attributes
        $productTags = ! empty($shopifyProduct['tags']) ? explode($shopifyProduct['tags'], ',') : [];
        $productAttributes = [];

        return [
           'name' => $name,
           'description' => $description ?? '',
           'slug' => $slug,
           'sku' => (string) $productId,
           'regionId' => $this->region->id,
           'price' => 0,
           'discountPrice' => 0,
           'quantity' => 1,
           'isPublished' => $shopifyProduct['status'] == 'active',
           'files' => $this->files['files'] ?? [],
           'source' => ShopifyConfigurationService::getKey(CustomFieldEnum::SHOPIFY_PRODUCT_ID->value, $this->company, $this->app, $this->region),
           'sourceId' => $productId,
           'customFields' => [
               [
                   'name' => ShopifyConfigurationService::getKey(CustomFieldEnum::SHOPIFY_PRODUCT_ID->value, $this->company, $this->app, $this->region),
                   'data' => $productId,
               ],
           ],
           'categories' => [
               [
                   'name' => ! empty($shopifyProduct['product_type']) ? $shopifyProduct['product_type'] : 'Uncategorized',
                   'code' => ! empty($shopifyProduct['product_type']) ? $shopifyProduct['product_type'] : 'Uncategorized',
                   'is_published' => true,
                   'position' => 1,
               ],
           ],
           'attributes' => [],
           'variants' => $this->mapVariantsForImport($shopifyProduct['variants'], $shopifyProduct['options']),
           'warehouses' => [
                [
                     'warehouse' => $this->warehouses->name,
                     'channel' => $this->channel->name,
                ],
           ],
        ];
    }

    public function mapVariantsForImport(array $variants, array $shopifyProductOptions): array
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
                'source' => ShopifyConfigurationService::getKey(CustomFieldEnum::SHOPIFY_VARIANT_ID->value, $this->company, $this->app, $this->region),
                'sourceId' => $variant['id'],
                'custom_fields' => [
                    [
                        'name' => ShopifyConfigurationService::getKey(CustomFieldEnum::SHOPIFY_VARIANT_ID->value, $this->company, $this->app, $this->region),
                        'data' => $variant['id'],
                    ],
                ],
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

    public function mapFilesForImport(array $files): void
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
                $filesSystemVariantImages[$file['variant_ids'][0]][] = $shopifyImage;
            }

            $i++;
        }

        $this->files = [
            'files' => $fileSystem,
            'filesSystemVariantImages' => $filesSystemVariantImages,
        ];
    }
}
