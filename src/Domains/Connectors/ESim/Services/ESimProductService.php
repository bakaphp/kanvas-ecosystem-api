<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim\Services;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\ESim\Enums\CustomFieldEnum;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Locations\Models\Countries;

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

        $attributes = [
            [
                'name' => 'product-provider',
                'value' => $destination['provider'],
            ],
        ];

        if (! empty($destination['countries'])) {
            $attributes[] = [
                'name' => 'countries',
                'value' => $this->mapCountriesAttribute($destination['countries']),
            ];
        }

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
            'attributes' => $attributes,
            'variants' => $this->mapVariant($destination, $destination['plans']) ?? [],
            'warehouses' => [
                 [
                      'warehouse' => $this->warehouses->name,
                      'channel' => $this->channel->name,
                 ],
            ],
         ];
    }

    protected function mapVariant($destination, array $plans): array
    {
        $productVariants = [];
        foreach ($plans as $variant) {
            $variantName = $variant['data'];

            $attributes = [
                [
                    'name' => 'esim_bundle_type',
                    'value' => $variant['sku'],
                ],[
                    'name' => 'esim_days',
                    'value' => $variant['duration'],
                ], [
                    'name' => 'Variant Type',
                    'value' => $variant['is_unlimited'] == 0 ? 'basic' : 'unlimited',
                ],[
                    'name' => 'Variant Duration',
                    'value' => $variant['duration'],
                ],[
                    'name' => 'Variant Network',
                    'value' => $variant['coverages'][0]['networks'][0]['name'] ?? $destination['provider'] ?? null,
                ],[
                    'name' => 'Variant Speed',
                    'value' => $variant['coverages'][0]['networks'][0]['types'][0] ?? 'LTE', //change to custom setting per app
                ],
            ];

            if (! empty($variant['price_range'])) {
                $attributes[] = [
                    'name' => 'esim_price_range',
                    'value' => $variant['price_range'],
                ];
            }

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
                'attributes' => $attributes,
            ];
        }

        return $productVariants;
    }

    protected function mapCountriesAttribute(array $countries): array
    {
        $countryCodes = array_map(fn ($country) => strtolower($country['country_code']), $countries);

        return Countries::whereIn('code', $countryCodes)->pluck('id')->toArray();
    }
}
