<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CMLink\Services;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Locations\Models\Countries;
use Kanvas\Regions\Models\Regions;

class CMLinkProductService
{
    public function __construct(
        protected Regions $region,
        protected ?UserInterface $user = null,
        protected ?Warehouses $warehouses = null,
        protected ?Channels $channel = null
    ) {
        $this->user = $user ?? $this->region->company->user;
        $this->warehouses = $warehouses ?? Warehouses::fromCompany($this->region->company)
            ->where('is_default', 1)
            ->where('regions_id', $this->region->id)
            ->firstOrFail();
        $this->channel = $channel ?? Channels::fromCompany($this->region->company)
            ->where('is_default', 1)
            ->firstOrFail();
    }

    public function mapProductToImport(array $bundles): array
    {
        $groupedProducts = [];

        foreach ($bundles['dataBundles'] as $bundle) {
            // Extract the base product name
            $fullName = $bundle['name'][0]['value'] ?? 'Unknown Product';
            $baseName = $this->extractBaseName($fullName);

            $sku = $bundle['id'];
            $price = ($bundle['priceInfo'][0]['price'] / 100);
            $originalPrice = ($bundle['originalPriceInfo'][0]['price'] / 100);

            // Construct the variant
            $variantAttributes = $this->mapVariantAttributes($bundle);
            $variant = [
                'name' => $fullName,
                'description' => $bundle['desc'][0]['value'] ?? '',
                'sku' => $sku,
                'price' => $price,
                'discountPrice' => $originalPrice,
                'is_published' => $bundle['status'] === 1,
                'slug' => $sku,
                'attributes' => $variantAttributes,
                'warehouse' => [
                    'id' => $this->warehouses->id,
                    'price' => $price,
                    'quantity' => 100000,
                    'sku' => $sku,
                    'is_new' => true,
                ],
            ];

            // Map product attributes
            $productAttributes = $this->mapProductAttributes($bundle);

            // Group variants under the same base name
            if (! isset($groupedProducts[$baseName])) {
                $groupedProducts[$baseName] = [
                    'name' => $baseName,
                    'description' => $bundle['desc'][0]['value'] ?? '',
                    'slug' => Str::slug($baseName),
                    'sku' => $sku,
                    'regionId' => $this->region->id,
                    'price' => $price,
                    'discountPrice' => $originalPrice,
                    'quantity' => 1,
                    'isPublished' => $bundle['status'] === 1,
                    'status' => $bundle['status'] ?? 0,
                    'files' => [
                        [
                            'name' => 'product_image',
                            'url' => $bundle['imgurl'] ?? '',
                        ],
                    ],
                    'source' => 'cmlink_product',
                    'sourceId' => $sku,
                    'customFields' => [
                        [
                            'name' => 'cmlink_product_id',
                            'data' => $sku,
                        ],
                    ],
                    'categories' => [
                        [
                            'name' => 'cmlink',
                            'code' => crc32('cmlink'),
                            'is_published' => true,
                            'position' => 1,
                        ],
                    ],
                    'productType' => [
                        'name' => 'CMLink',
                        'weight' => 0,
                    ],
                    'attributes' => $productAttributes,
                    'variants' => [],
                    'warehouses' => [
                        [
                            'warehouse' => $this->warehouses->name,
                            'channel' => $this->channel->name,
                        ],
                    ],
                ];
            }

            // Add the variant to the grouped product
            $groupedProducts[$baseName]['variants'][] = $variant;
        }

        // Return grouped products as an indexed array
        return array_values($groupedProducts);
    }

    protected function mapVariantAttributes(array $bundle): array
    {
        return [
            [
                'name' => 'esim_bundle_type',
                'value' => $bundle['id'],
            ],
            [
                'name' => 'esim_days',
                'value' => $bundle['period'] ?? 0,
            ],
            [
                'name' => 'Variant Type',
                'value' => isset($bundle['desc'][0]['value']) && str_contains(strtolower($bundle['desc'][0]['value']), 'unlimited') ? 'unlimited' : 'basic',
            ],
            [
                'name' => 'Variant Duration',
                'value' => $bundle['period'] ?? 0,
            ],
            [
                'name' => 'Variant Network',
                'value' => $bundle['desc'][0]['value'] ?? 'Unknown',
            ],
            [
                'name' => 'Variant Speed',
                'value' => 'LTE', // Replace with actual speed if available
            ],
            [
                'name' => 'Rechargeability',
                'value' => $bundle['activationMode'] ?? 'Unknown',
            ],
            [
                'name' => 'Has Phone Number',
                'value' => 0,
            ],
        ];
    }

    protected function mapProductAttributes(array $bundle): array
    {
        $attributes = [
            [
                'name' => 'product-provider',
                'value' => 'CMLink',
            ],
        ];

        if (! empty($bundle['countries'])) {
            $attributes[] = [
                'name' => 'countries',
                'value' => $this->mapCountriesAttribute($bundle['countries']),
            ];
        }

        if (! empty($bundle['recommendedPlans'])) {
            /*   $attributes[] = [
                  'name' => 'recommended_plans',
                  'value' => $bundle['recommendedPlans'],
              ]; */
        }

        return $attributes;
    }

    protected function mapCountriesAttribute(array $countries): array
    {
        $countryCodes = array_map(fn ($country) => strtolower($country['country_code']), $countries);

        return Countries::whereIn('code', $countryCodes)->pluck('id')->toArray();
    }

    protected function extractBaseName(string $fullName): string
    {
        // Remove variations (e.g., "7 Days", "2GB/day high speed") to get the base name
        return preg_replace('/\d+ (Days|GB).*$/i', '', $fullName);
    }

    protected function extractMCCs(array $cardPools): array
    {
        $mccs = [];
        foreach ($cardPools as $poolId => $details) {
            foreach ($details as $variant) {
                if (isset($variant['mcc'])) {
                    $mccs[] = $variant['mcc'];
                }
            }
        }

        return array_unique($mccs);
    }
}
