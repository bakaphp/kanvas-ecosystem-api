<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CMLink\Services;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\CMLink\Enums\ConfigurationEnum;
use Kanvas\Connectors\CMLink\Enums\CustomFieldEnum;
use Kanvas\Connectors\ESim\Enums\CustomFieldEnum as EnumsCustomFieldEnum;
use Kanvas\Connectors\ESim\Enums\ProductTypeEnum;
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
        $useCalendarVariants = (bool) $this->region->app->get(ConfigurationEnum::USE_CALENDAR_VARIANTS->value);

        foreach ($bundles['dataBundles'] as $bundle) {
            // Extract the base product name
            $fullName = $bundle['name'][0]['value'] ?? 'Unknown Product';
            $baseName = $this->extractBaseName($fullName);

            $sku = $bundle['id'];
            $price = ($bundle['priceInfo'][0]['price'] / 100);
            $originalPrice = ($bundle['originalPriceInfo'][0]['price'] / 100);

            // Construct the variant
            $variantAttributes = $this->mapVariantAttributes($bundle);
            $variants = ! $useCalendarVariants ? $this->getVariant(
                $fullName,
                $sku,
                $price,
                $originalPrice,
                $variantAttributes,
                $bundle
            ) : $this->getCalendarVariants(
                $fullName,
                $sku,
                $price,
                $originalPrice,
                $variantAttributes,
                $bundle
            );

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
                            'name' => 'logo.jpg',
                            'url' => $bundle['imgurl'] ?? '',
                        ],
                    ],
                    'source' => CustomFieldEnum::CMLINK_SOURCE_ID->value,
                    'sourceId' => $sku,
                    'customFields' => [
                        [
                            'name' => CustomFieldEnum::CMLINK_PRODUCT_ID->value,
                            'data' => $sku,
                        ],
                    ],
                    'categories' => [
                        [
                            'name' => 'cmlink',
                            'code' => crc32('cmlink'),
                            'is_published' => true,
                            'position' => 1,
                        ],[
                            'name' => 'esim',
                            'code' => crc32('esim'),
                            'is_published' => true,
                            'position' => 1,
                        ],
                    ],
                    'productType' => [
                        'name' => ProductTypeEnum::getTypeByName($baseName)->value,
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
            if (! $useCalendarVariants) {
                $groupedProducts[$baseName]['variants'][] = $variants;
            } else {
                $groupedProducts[$baseName]['variants'] = $variants;
            }
        }

        // Return grouped products as an indexed array
        return array_values($groupedProducts);
    }

    protected function getVariant(
        string $fullName,
        string $sku,
        float $price,
        float $originalPrice,
        array $variantAttributes,
        array $bundle
    ): array {
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

        return $variant;
    }

    protected function getCalendarVariants(
        string $fullName,
        string $sku,
        float $price,
        float $originalPrice,
        array $variantAttributes,
        array $bundle
    ) {
        $period = $bundle['period'] ?? 0;

        if ($period == 0) {
            return $this->getVariant(
                $fullName,
                $sku,
                $price,
                $originalPrice,
                $variantAttributes,
                $bundle
            );
        }

        $i = 1;
        while ($i <= $period) {
            $variant = $this->getVariant(
                $fullName,
                $sku,
                $price,
                $originalPrice,
                $variantAttributes,
                $bundle
            );
            // Add the updated 'esim_days' entry
            $attributes[] = [
                'name' => 'esim_days',
                'value' => $period,
            ];
            $attributes[] = [
                'name' => 'Variant Duration',
                'value' => $period,
            ];

            $sku = Str::simpleSlug($sku . '-' . $i);
            //$sourceId = $variant['id'] . '-' . $variantByPriceRange['days'];

            $variant['name'] = $fullName . ' - ' . $period . ' Days';
            $variant['sku'] = $sku . '-' . $period;
            $variant['attributes'][] = [
                'name' => 'esim_days',
                'value' => $period,
            ];

            $variant['attributes'] = [
                [
                    'name' => EnumsCustomFieldEnum::VARIANT_ESIM_ID->value,
                    'data' => $sku,
                ],[
                    'name' => 'parent_sku',
                    'data' => $sku,
                ],
            ];

            $variants[] = $variant;
            $i++;
        }

        return $variants;
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
            [
                'name' => 'Data',
                'value' => isset($bundle['name'][0]['value'])
                            ? (preg_match('/\b(\d+)(MB|GB)\b/i', $bundle['name'][0]['value'], $matches) ? $matches[0] : 'unknown')
                            : 'unknown',
            ],
        ];
    }

    protected function mapProductAttributes(array $bundle): array
    {
        $attributes = [
            [
                'name' => 'product-provider',
                'value' => ConfigurationEnum::NAME->value,
            ],
        ];

        //mobile country codes
        $mccs = $this->extractMCCs($bundle['cardPools'] ?? []);

        if (! empty($mccs)) {
            $attributes[] = [
                'name' => 'countries',
                'value' => $this->mapCountriesAttribute($mccs),
            ];
            $attributes[] = [
                'name' => 'Countries Code',
                'value' => $this->mapCountriesAttribute($mccs, 'code'),
            ];
        }

        if (! empty($bundle['recommendedPlans'])) {
            /*   $attributes[] = [
                  'name' => 'recommended_plans',
                  'value' => $bundle['recommendedPlans'],
              ]; */
        }

        $attributes[] = [
            'name' => 'max_unlimited_days',
            'value' => $bundle['period'] ?? 0,
        ];
        $attributes[] = [
            'name' => 'refueling_package',
            'value' => $bundle['refuelingPackage'] ?? null,
        ];

        return $attributes;
    }

    protected function mapCountriesAttribute(array $countries, string $returnField = 'id'): array
    {
        // Convert the MCC array to a lowercase string array
        $mccCodes = array_map(fn ($mcc) => strtolower($mcc), $countries);

        // Query the database to find matching countries by MCC
        return Countries::whereIn('mcc', $mccCodes)->pluck($returnField)->toArray();
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
