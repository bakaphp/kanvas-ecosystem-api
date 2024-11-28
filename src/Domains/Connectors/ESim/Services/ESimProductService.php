<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\Services;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\ESim\Enums\CustomFieldEnum;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

class ESimProductService
{
    public function __construct(
        protected Regions $region,
        protected ?UserInterface $user = null,
        protected ?Warehouses $warehouses = null,
        protected ?Channels $channel = null
    ) {
        $this->user = $user ?? $this->region->company->user;
        $this->warehouses = $warehouses ?? Warehouses::fromCompany($this->region->company)->where('is_default', 1)->where('regions_id', $this->region->id)->firstOrFail();
        $this->channel = $channel ?? Channels::fromCompany($this->region->company)->where('is_default', 1)->firstOrFail();
    }

    public function mapProductToImport(array $destination): array
    {
        $sku = Str::slug($destination['provider'] . ' ' . $destination['id']);

        $files = [
            [
                'name' => basename($destination['image_url']),
                'url' => $destination['image_url'],
            ],
        ];

        $category = 'esim';
        $productType = $destination['type'];

        return [
            'name' => $destination['name'],
            'description' => $destination['name'] ?? '',
            'slug' => $sku,
            'sku' => $sku,
            'regionId' => $this->region->id,
            'price' => 0,
            'discountPrice' => 0,
            'quantity' => 1,
            'isPublished' => (int) $destination['status'],
            'status' => (int) $destination['status'],
            'files' => $files ?? [],
            'source' => CustomFieldEnum::PRODUCT_ESIM_ID->value,
            'sourceId' => $destination['id'],
            'customFields' => [
                [
                    'name' => CustomFieldEnum::PRODUCT_ESIM_ID->value,
                    'data' => $destination['id'],
                ],
            ],
            'categories' => [
                [
                    'name' => $category,
                    'code' => crc32($category),
                    'is_published' => true,
                    'position' => 1,
                ],
            ],
            'productType' => [
                 'name' => $productType,
                 'weight' => 0,
            ],
            'attributes' => [
                [
                    'name' => 'provider',
                    'value' => $destination['provider'],
                ],
            ],
            'variants' => $this->mapVariant($destination['plans']) ?? [],
            'warehouses' => [
                 [
                      'warehouse' => $this->warehouses->name,
                      'channel' => $this->channel->name,
                 ],
            ],
         ];
    }

    protected function mapVariant(array $plans): array
    {
        foreach ($plans as $variant) {
            $variantName = $variant['name'];

            $productVariants[] = [
                'name' => $variantName,
                'description' => $variant['description'],
                'sku' => $variant['sku'],
                'price' => (float) $variant['public_price'],
                'discountPrice' => (float) $variant['public_price'],
                'is_published' => true,
                'slug' => Str::slug(str_replace('_', '-', $variant['sku'])),
                'files' => [],
                'source' => CustomFieldEnum::VARIANT_ESIM_ID->value,
                'sourceId' => $variant['id'],
                'barcode' => null,
                'custom_fields' => [
                    [
                        'name' => CustomFieldEnum::VARIANT_ESIM_ID->value,
                        'data' => $variant['id'],
                    ],
                ],
                'warehouse' => [
                    'id' => $this->warehouses->id,
                    'price' => (float) $variant['public_price'],
                    'quantity' => 100000,
                    'sku' => $variant['sku'],
                    'is_new' => true,
                ],
                'attributes' => [
                    [
                        'name' => 'esim_bundle_type',
                        'value' => $variant['sku'],
                    ],[
                        'name' => 'esim_days',
                        'value' => $variant['duration'],
                    ],
                ],
            ];
        }

        return $productVariants;
    }
}
