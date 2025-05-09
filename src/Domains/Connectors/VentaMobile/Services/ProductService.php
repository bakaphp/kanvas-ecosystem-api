<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VentaMobile\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\ESim\Enums\ProductTypeEnum;
use Kanvas\Connectors\VentaMobile\Client;
use Kanvas\Connectors\VentaMobile\Enums\ConfigurationEnum;
use Kanvas\Connectors\VentaMobile\Enums\CustomFieldEnum;
use Kanvas\Connectors\VentaMobile\Enums\PlanTypeEnum;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Locations\Models\Countries;
use Kanvas\Regions\Models\Regions;

class VentaMobileProductService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected Regions $region,
        protected ?UserInterface $user = null,
        protected ?Warehouses $warehouses = null,
        protected ?Channels $channel = null
    ) {
        $this->client = new Client($app, $company);
        $this->user = $user ?? $this->region->company->user;
        $this->warehouses = $warehouses ?? Warehouses::fromCompany($this->region->company)
            ->where('is_default', 1)
            ->where('regions_id', $this->region->id)
            ->firstOrFail();
        $this->channel = $channel ?? Channels::fromCompany($this->region->company)
            ->where('is_default', 1)
            ->firstOrFail();
    }

    /**
     * Get all tariff plans.
     */
    public function getAllTariffPlans(): array
    {
        return $this->client->get('/get/dictionary', [
            'dict' => 'tariff_plan',
        ]);
    }

    /**
     * Get all extensions (data plans).
     */
    public function getAllExtensions(): array
    {
        return $this->client->get('/get/dictionary', [
            'dict' => 'extension',
        ]);
    }

    /**
     * Get all tariff packets with details.
     */
    public function getAllTariffPackets(): array
    {
        return $this->client->get('/get/dictionary', [
            'dict' => 'tariffs_packets',
        ]);
    }

    /**
     * Map VentaMobile products to Kanvas import format.
     */
    public function mapProductsToImport(): array
    {
        // Get all the necessary data
        $tariffPlans = $this->getAllTariffPlans();
        $extensions = $this->getAllExtensions();
        $tariffPackets = $this->getAllTariffPackets();

        // Create a map of tariff plan IDs to names
        $tariffPlanMap = [];
        foreach ($tariffPlans as $plan) {
            $tariffPlanMap[$plan['ID']] = $plan['name'];
        }

        // Create a map of extensions with detailed info
        $extensionDetails = [];
        foreach ($tariffPackets as $packet) {
            if (! isset($packet['tariff_packets']) || ! is_array($packet['tariff_packets'])) {
                continue;
            }

            $tariffPlanId = $packet['ID'];
            $tariffPlanName = $packet['name'] ?? $tariffPlanMap[$tariffPlanId] ?? 'Unknown Plan';

            foreach ($packet['tariff_packets'] as $extension) {
                $extensionId = $extension['ID_EXTENSION'] ?? null;
                if (! $extensionId) {
                    continue;
                }

                $extensionDetails[$extensionId] = [
                    'tariff_plan_id' => $tariffPlanId,
                    'tariff_plan_name' => $tariffPlanName,
                    'name' => $extension['V_NAME'] ?? 'Unknown',
                    'description' => $extension['V_DESC'] ?? '',
                    'data_amount' => $extension['N_VALUE'] ?? 0,
                    'data_unit' => $extension['V_UNIT'] ?? 'Byte',
                    'validity_days' => $extension['N_PERIODS_COUNT'] ?? 0,
                    'balance_id' => $extension['ID_BALANCE'] ?? null,
                ];
            }
        }

        // Group products by region/country
        $groupedProducts = [];

        foreach ($extensions as $extension) {
            $id = $extension['ID'];
            $name = $extension['name'];
            $description = $extension['description'] ?? '';
            $details = $extensionDetails[$id] ?? [];

            // Extract the region name
            $region = $this->extractRegionFromName($name);
            if (empty($region)) {
                continue; // Skip if we can't determine the region
            }

            // Create a unique base name for the product group
            $baseName = "VentaMobile $region";

            // Format the data amount
            $dataAmount = $this->formatDataSize($details['data_amount'] ?? 0);
            $validityDays = $details['validity_days'] ?? 0;

            // Determine variant type - basic is default
            $variantType = PlanTypeEnum::BASIC->value;

            // Prepare the variant
            $variant = $this->getVariant(
                $name . ' - ' . $dataAmount . ' for ' . $validityDays . ' days',
                'VENTA-EXT-' . $id,
                10.00, // Default price - replace with actual pricing logic
                15.00, // Default original price - replace with actual pricing logic
                $this->mapVariantAttributes($extension, $details),
                $variantType,
                $extension,
                $details
            );

            // Create or append to the product group
            if (! isset($groupedProducts[$baseName])) {
                $groupedProducts[$baseName] = [
                    'name' => $baseName,
                    'description' => 'VentaMobile eSIM data plans for ' . $region,
                    'slug' => Str::slug($baseName),
                    'sku' => 'VENTA-REGION-' . Str::slug($region),
                    'regionId' => $this->region->id,
                    'price' => 10.00, // Default price - replace with actual pricing logic
                    'discountPrice' => 15.00, // Default original price - replace with actual pricing logic
                    'quantity' => 1,
                    'isPublished' => true,
                    'status' => 1,
                    'files' => [
                        [
                            'name' => 'logo.jpg',
                            'url' => 'https://ventamobile.net/logo.jpg', // Replace with actual logo URL
                        ],
                    ],
                    'source' => CustomFieldEnum::VENTAMOBILE_SOURCE_ID->value,
                    'sourceId' => $id,
                    'customFields' => [
                        [
                            'name' => CustomFieldEnum::VENTAMOBILE_PRODUCT_ID->value,
                            'data' => $id,
                        ],
                    ],
                    'categories' => [
                        [
                            'name' => 'ventamobile',
                            'code' => crc32('ventamobile'),
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
                    'attributes' => $this->mapProductAttributes($extension, $details, $region),
                    'variants' => [],
                    'warehouses' => [
                        [
                            'warehouse' => $this->warehouses->name,
                            'channel' => $this->channel->name,
                        ],
                    ],
                ];
            }

            // Add the variant to the product
            $groupedProducts[$baseName]['variants'][] = $variant;
        }

        // Return as indexed array
        return array_values($groupedProducts);
    }

    /**
     * Create variant array for a product.
     */
    protected function getVariant(
        string $fullName,
        string $sku,
        float $price,
        float $originalPrice,
        array $variantAttributes,
        string $variantType,
        array $extension,
        array $details
    ): array {
        return [
            'name' => $fullName,
            'description' => $extension['description'] ?? '',
            'sku' => $sku,
            'price' => $price,
            'discountPrice' => $originalPrice,
            'is_published' => true,
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
    }

    /**
     * Map variant attributes.
     */
    protected function mapVariantAttributes(array $extension, array $details): array
    {
        $region = $this->extractRegionFromName($extension['name']);
        $dataAmount = $this->formatDataSize($details['data_amount'] ?? 0);

        return [
            [
                'name' => 'esim_bundle_type',
                'value' => $extension['ID'],
            ],
            [
                'name' => 'esim_days',
                'value' => $details['validity_days'] ?? 0,
            ],
            [
                'name' => 'Variant Type',
                'value' => PlanTypeEnum::BASIC->value,
            ],
            [
                'name' => 'Variant Duration',
                'value' => $details['validity_days'] ?? 0,
            ],
            [
                'name' => 'Variant Network',
                'value' => 'VentaMobile',
            ],
            [
                'name' => 'Variant Speed',
                'value' => 'LTE',
            ],
            [
                'name' => 'Rechargeability',
                'value' => 'Manual',
            ],
            [
                'name' => 'Has Phone Number',
                'value' => 0,
            ],
            [
                'name' => 'Data',
                'value' => $dataAmount,
            ],
            [
                'name' => 'Region',
                'value' => $region,
            ],
        ];
    }

    /**
     * Map product attributes.
     */
    protected function mapProductAttributes(array $extension, array $details, string $region): array
    {
        $attributes = [
            [
                'name' => 'product-provider',
                'value' => ConfigurationEnum::NAME->value,
            ],
            [
                'name' => 'max_unlimited_days',
                'value' => $details['validity_days'] ?? 0,
            ],
        ];

        // Map countries based on region
        $countries = $this->mapCountriesByRegion($region);

        if (! empty($countries)) {
            $attributes[] = [
                'name' => 'countries',
                'value' => $this->mapCountriesAttribute($countries),
            ];
            $attributes[] = [
                'name' => 'Countries Code',
                'value' => $this->mapCountriesAttribute($countries, 'code'),
            ];
        }

        return $attributes;
    }

    /**
     * Format data size to human-readable format.
     */
    protected function formatDataSize(int $bytes): string
    {
        if ($bytes >= 1073741824) { // 1 GB
            return round($bytes / 1073741824, 2) . 'GB';
        } elseif ($bytes >= 1048576) { // 1 MB
            return round($bytes / 1048576, 2) . 'MB';
        }

        return '0MB';
    }

    /**
     * Extract region from extension name.
     */
    protected function extractRegionFromName(string $name): string
    {
        // Extract country or region from plan names like "[370] Dominican Republic", "Simlimits TIM Europe"
        if (preg_match('/\[\d+\]\s*(.*?)$/', $name, $matches)) {
            return trim($matches[1]);
        }

        // Extract region from names like "Simlimits TIM Europe", "Simlimits Orange Europe"
        if (preg_match('/Simlimits\s+(?:TIM|Orange)\s+(.*?)$/', $name, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    /**
     * Map countries by region name.
     */
    protected function mapCountriesByRegion(string $region): array
    {
        // This is a simplified example - in a real implementation, you would have a proper mapping
        $regionToCountries = [
            'Dominican Republic' => ['DO'],
            'El Salvador' => ['SV'],
            'Europe' => ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE'],
            'America' => ['US', 'CA', 'MX', 'BR', 'AR', 'CO', 'CL', 'PE'],
            'Asia' => ['CN', 'JP', 'KR', 'IN', 'MY', 'SG', 'TH', 'VN', 'ID', 'PH'],
            'Africa' => ['ZA', 'EG', 'NG', 'KE', 'MA', 'GH', 'TN'],
            'Oceania' => ['AU', 'NZ', 'FJ'],
        ];

        foreach ($regionToCountries as $regionName => $countries) {
            if (stripos($region, $regionName) !== false) {
                return $countries;
            }
        }

        return [];
    }

    /**
     * Map countries to Kanvas format.
     */
    protected function mapCountriesAttribute(array $countryCodes, string $returnField = 'id'): array
    {
        // Convert the country codes to lowercase
        $codes = array_map(fn ($code) => strtolower($code), $countryCodes);

        // Query the database to find matching countries by code
        return Countries::whereIn('code', $codes)->pluck($returnField)->toArray();
    }
}
