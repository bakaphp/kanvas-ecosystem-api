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
                'name' => 'flag.svg',
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
        // Map the variants
        $variants = $this->mapVariant($destination, $destination['plans']) ?? [];
        $recommendationAttribute = $this->mapProductRecommendationAttribute($variants);

        if (! empty($recommendationAttribute)) {
            $attributes[] = [
                'name' => 'recommended_plans',
                'value' => $recommendationAttribute,
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
            'variants' => $variants,
            'warehouses' => [
                 [
                      'warehouse' => $this->warehouses->name,
                      'channel' => $this->channel->name,
                 ],
            ],
         ];
    }

    protected function mapProductRecommendationAttribute(array $variants): array
    {
        if (empty($variants)) {
            return []; // Return empty if there are no variants
        }

        $sections = [];
        $sectionCounter = 1;

        // Sort variants by `esim_days` in ascending order
        usort($variants, function ($a, $b) {
            $aDays = $this->extractAttributeValue($a['attributes'], 'esim_days');
            $bDays = $this->extractAttributeValue($b['attributes'], 'esim_days');

            return $aDays <=> $bDays; // Ascending order
        });

        // Split sorted variants into two groups (low and high packages)
        $sortedSkus = array_column($variants, 'sku');
        $groupSize = max(1, (int)ceil(count($sortedSkus) / 2)); // Explicitly cast ceil result to int
        $groups = array_chunk($sortedSkus, $groupSize);

        $lowPackages = $groups[0] ?? [];
        $highPackages = $groups[1] ?? [];

        // Create Sections 1 to 4 with lower packages
        $lowPackageGroups = $this->groupVariantsIntoSections($lowPackages, 4);
        foreach ($lowPackageGroups as $group) {
            if ($sectionCounter > 4) {
                break;
            }
            $sections[] = [
                'name' => 'section_' . $sectionCounter,
                'variantSku' => $group,
            ];
            $sectionCounter++;
        }

        // Create Sections 5 to 10 with higher packages
        $highPackageGroups = $this->groupVariantsIntoSections($highPackages, 4);
        while (count($highPackageGroups) < 6) {
            $highPackageGroups[] = $highPackages; // Add repeated high packages if needed
        }
        $highPackageGroups = array_slice($highPackageGroups, 0, 6); // Ensure only 6 groups

        foreach ($highPackageGroups as $group) {
            $sections[] = [
                'name' => 'section_' . $sectionCounter,
                'variantSku' => $group,
            ];
            $sectionCounter++;
        }

        return $sections;
    }

    // Helper function to group variants into sections of specified size
    protected function groupVariantsIntoSections(array $variants, int $sectionSize): array
    {
        $groups = [];

        foreach (array_chunk($variants, $sectionSize) as $group) {
            $groups[] = $group;
        }

        return $groups;
    }

    // Extract attribute value by name from the attributes array
    protected function extractAttributeValue(array $attributes, string $attributeName): int
    {
        foreach ($attributes as $attribute) {
            if ($attribute['name'] === $attributeName) {
                return (int) $attribute['value'];
            }
        }

        return 0; // Default value if the attribute is not found
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
                    'value' => $variant['is_unlimited'] == 1 || Str::contains(Str::lower($variantName), 'unlimited') ? 'unlimited' : 'basic',
                ],[
                    'name' => 'Variant Duration',
                    'value' => $variant['duration'],
                ],[
                    'name' => 'Variant Network',
                    'value' => $variant['coverages'][0]['networks'][0]['name'] ?? $destination['provider'] ?? null,
                ],[
                    'name' => 'Variant Speed',
                    'value' => $variant['coverages'][0]['networks'][0]['types'][0] ?? 'LTE', //change to custom setting per app
                ],[
                    'name' => 'Rechargeability',
                    'value' => $variant['rechargeability'],
                ],[
                    'name' => 'Has Phone Number',
                    'value' => 0,
                ],
            ];

            if (! empty($variant['price_range'])) {
                $attributes[] = [
                    'name' => 'esim_price_range',
                    'value' => $variant['price_range'],
                ];
            }

            $price = number_format($variant['public_price'], 2, '.', '');
            $productVariants[] = [
                'name' => $variantName,
                'description' => $variant['description'],
                'sku' => $variant['sku'],
                'price' => $price,
                'discountPrice' => $price,
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
                    'price' => $price,
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
