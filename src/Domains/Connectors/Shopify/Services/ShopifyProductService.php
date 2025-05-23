<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\Shopify\Enums\ConfigEnum;
use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Regions\Models\Regions;

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
        $productTags = ! empty($shopifyProduct['tags']) ? explode(',', $shopifyProduct['tags']) : [];
        $productTags = array_map(function ($tag) {
            return ['name' => $tag];
        }, $productTags);
        $productAttributes = [];

        $productType = $shopifyProduct['product_type'] ?? 'Default';
        $productCategory = ! empty($shopifyProduct['category']) ? $shopifyProduct['category']['name'] : 'Uncategorized';

        if ($this->app->get(ConfigEnum::SHOPIFY_PRODUCT_TYPE_AS_CATEGORY->value)) {
            $productCategory = $productType;
        }

        return [
           'name' => $name,
           'description' => $description ?? '',
           'slug' => $slug,
           'sku' => (string) $productId,
           'regionId' => $this->region->id,
           'price' => 0,
           'discountPrice' => 0,
           'quantity' => 1,
           'isPublished' => (int) ($shopifyProduct['status'] == 'active'),
           'status' => $shopifyProduct['status'],
           'files' => $this->files['files'] ?? [],
           'source' => ShopifyConfigurationService::getKey(CustomFieldEnum::SHOPIFY_PRODUCT_ID->value, $this->company, $this->app, $this->region),
           'sourceId' => $productId,
           'customFields' => [
               [
                   'name' => ShopifyConfigurationService::getKey(CustomFieldEnum::SHOPIFY_PRODUCT_ID->value, $this->company, $this->app, $this->region),
                   'data' => $productId,
               ],
           ],
           'vendor' => $shopifyProduct['vendor'],
           'categories' => [
               [
                   'name' => $productCategory,
                   'code' => ! empty($shopifyProduct['category']) ? Str::afterLast($shopifyProduct['category']['admin_graphql_api_id'], '/') : 'Uncategorized',
                   'is_published' => true,
                   'position' => 1,
               ],
           ],
           'productType' => [
                'name' => $productType,
                'weight' => 0,
           ],
           'attributes' => [],
           'variants' => $this->mapVariantsForImport($shopifyProduct['variants'], $shopifyProduct['options']),
           'warehouses' => [
                [
                     'warehouse' => $this->warehouses->name,
                     'channel' => $this->channel->name,
                ],
           ],
           'tags' => $productTags,
        ];
    }

    public function mapVariantsForImport(array $variants, array $shopifyProductOptions): array
    {
        foreach ($variants as $variant) {
            $variantName = $variant['title'];

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
                'barcode' => $variant['barcode'],
                'custom_fields' => [
                    [
                        'name' => ShopifyConfigurationService::getKey(CustomFieldEnum::SHOPIFY_VARIANT_ID->value, $this->company, $this->app, $this->region),
                        'data' => $variant['id'],
                    ],
                    [
                        'name' => ShopifyConfigurationService::getKey(CustomFieldEnum::SHOPIFY_VARIANT_INVENTORY_ID->value, $this->company, $this->app, $this->region),
                        'data' => $variant['inventory_item_id'],
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
            $cleanedFilename = Str::before($filename, '?'); //shopify name may have query string

            $shopifyImage = [
                'url' => $file['src'],
                'name' => $cleanedFilename,
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
