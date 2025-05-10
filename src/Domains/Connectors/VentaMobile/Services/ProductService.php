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
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Locations\Models\Countries;
use Kanvas\Regions\Models\Regions;

class ProductService
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
     * Get SIM card types.
     */
    public function getSimCardTypes(): array
    {
        return $this->client->get('/get/dictionary', [
            'dict' => 'simType',
        ]);
    }

    /**
     * Get available offers.
     */
    public function getOffers(): array
    {
        return $this->client->get('/get/dictionary', [
            'dict' => 'offer',
        ]);
    }

    /**
     * Get period length types.
     */
    public function getPeriodLengthTypes(): array
    {
        return $this->client->get('/get/dictionary', [
            'dict' => 'period_length_type',
        ]);
    }

    /**
     * Get blocking reasons.
     */
    public function getBlockingReasons(): array
    {
        return $this->client->get('/get/dictionary', [
            'dict' => 'blocking_reason',
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
        $simTypes = $this->getSimCardTypes();

        // Create a map of tariff plan IDs to names
        $tariffPlanMap = [];
        foreach ($tariffPlans as $plan) {
            $tariffPlanMap[$plan['ID']] = $plan['name'];
        }

        // Create a map of SIM types
        $simTypeMap = [];
        foreach ($simTypes as $simType) {
            $simTypeMap[$simType['ID']] = $simType['name'];
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
                    'period_length_type' => $extension['N_PERIOD_LENGTH'] ?? 0,
                    'compatible_sim_types' => $this->getCompatibleSimTypesForTariffPlan($tariffPlanId, $simTypeMap),
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

            // Set default prices - in a real implementation, you would have a pricing strategy
            $price = 10.00;
            $originalPrice = 15.00;

            // For data plans with larger data amounts, increase the price proportionally
            if (isset($details['data_amount'])) {
                $gbAmount = ($details['data_amount'] / 1073741824); // Convert to GB
                if ($gbAmount >= 1) {
                    $price = round(10.00 * $gbAmount, 2);
                    $originalPrice = round($price * 1.3, 2); // 30% markup for original price
                }
            }

            // Prepare the variant
            $variant = $this->getVariant(
                $name . ' - ' . $dataAmount . ' for ' . $validityDays . ' days',
                'VENTA-EXT-' . $id,
                $price,
                $originalPrice,
                $this->mapVariantAttributes($extension, $details),
                $variantType,
                $extension,
                $details
            );

            // Create or append to the product group
            if (! isset($groupedProducts[$baseName])) {
                // Get logo URL (placeholder - would need to be retrieved from your CMS or config)
                $logoUrl = $this->getLogoUrlForRegion($region);

                $groupedProducts[$baseName] = [
                    'name' => $baseName,
                    'description' => 'VentaMobile eSIM data plans for ' . $region,
                    'slug' => Str::slug($baseName),
                    'sku' => 'VENTA-REGION-' . Str::slug($region),
                    'regionId' => $this->region->id,
                    'price' => $price,
                    'discountPrice' => $originalPrice,
                    'quantity' => 1,
                    'isPublished' => true,
                    'status' => 1,
                    'files' => [
                        [
                            'name' => 'logo.jpg',
                            'url' => $logoUrl,
                        ],
                    ],
                    'source' => CustomFieldEnum::VENTAMOBILE_SOURCE_ID->value,
                    'sourceId' => $id,
                    'customFields' => [
                        [
                            'name' => CustomFieldEnum::VENTAMOBILE_PRODUCT_ID->value,
                            'data' => $id,
                        ],
                        [
                            'name' => 'tariff_plan_id',
                            'data' => $details['tariff_plan_id'] ?? null,
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
                        [
                            'name' => strtolower($region),
                            'code' => crc32(strtolower($region)),
                            'is_published' => true,
                            'position' => 2,
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
            'customFields' => [
                [
                    'name' => 'extension_id',
                    'data' => $extension['ID'],
                ],
                [
                    'name' => 'data_amount_bytes',
                    'data' => $details['data_amount'] ?? 0,
                ],
                [
                    'name' => 'validity_days',
                    'data' => $details['validity_days'] ?? 0,
                ],
                [
                    'name' => 'tariff_plan_id',
                    'data' => $details['tariff_plan_id'] ?? null,
                ],
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
        $validityDays = $details['validity_days'] ?? 0;
        $compatibleSimTypes = $details['compatible_sim_types'] ?? ['eSIM'];

        $attributes = [
            [
                'name' => 'esim_bundle_type',
                'value' => $extension['ID'],
            ],
            [
                'name' => 'esim_days',
                'value' => $validityDays,
            ],
            [
                'name' => 'Variant Type',
                'value' => PlanTypeEnum::BASIC->value,
            ],
            [
                'name' => 'Variant Duration',
                'value' => $validityDays,
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
            [
                'name' => 'Compatible SIM Types',
                'value' => implode(', ', $compatibleSimTypes),
            ],
            [
                'name' => 'Can Delete Extension',
                'value' => 'Only if unused', // Based on documentation section 14
            ],
            [
                'name' => 'tariff_plan_id',
                'value' => $details['tariff_plan_id'] ?? null,
            ],
            [
                'name' => 'Period Type',
                'value' => $details['period_length_type'] ?? 0,
            ],
        ];

        return $attributes;
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
            [
                'name' => 'provider_description',
                'value' => 'VentaMobile is a Latvian operator offering mobile communication services, roaming and traffic transit.',
            ],
            [
                'name' => 'activation_method',
                'value' => 'API',
            ],
            [
                'name' => 'extension_purchase_explanation',
                'value' => 'An extension can be deleted only if there have been no consumptions for it.',
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

    /**
     * Get logo URL for a region.
     * This is a placeholder method - in a real implementation, you would retrieve logos from your CMS or config.
     */
    protected function getLogoUrlForRegion(string $region): string
    {
        // Default logo URL - in a real implementation, you would have region-specific logos
        $defaultLogo = 'https://ventamobile.net/logo.jpg';

        // Region-specific logos mapping
        $logoMap = [
            'Dominican Republic' => 'https://ventamobile.net/logos/dominican_republic.jpg',
            'Europe' => 'https://ventamobile.net/logos/europe.jpg',
            'America' => 'https://ventamobile.net/logos/america.jpg',
            'Asia' => 'https://ventamobile.net/logos/asia.jpg',
            'Africa' => 'https://ventamobile.net/logos/africa.jpg',
            'Oceania' => 'https://ventamobile.net/logos/oceania.jpg',
        ];

        // Return region-specific logo if available, otherwise default
        foreach ($logoMap as $regionName => $logo) {
            if (stripos($region, $regionName) !== false) {
                return $logo;
            }
        }

        return $defaultLogo;
    }

    /**
     * Get compatible SIM types for a tariff plan.
     * This is a placeholder method - in a real implementation, you would have this mapping in your database or config.
     */
    protected function getCompatibleSimTypesForTariffPlan(int $tariffPlanId, array $simTypeMap): array
    {
        // Example mapping of tariff plans to compatible SIM types
        // In a real implementation, this would be based on actual compatibility data
        $compatibilityMap = [
            4026 => [5, 7], // Assume SIM type IDs 5 and 7 are compatible with tariff plan 4026
            4040 => [5], // Assume SIM type ID 5 is compatible with tariff plan 4040
        ];

        // Default to all SIM types if no specific mapping exists
        $compatibleIds = $compatibilityMap[$tariffPlanId] ?? array_keys($simTypeMap);

        // Map IDs to names
        $compatibleTypes = [];
        foreach ($compatibleIds as $id) {
            if (isset($simTypeMap[$id])) {
                $compatibleTypes[] = $simTypeMap[$id];
            }
        }

        return ! empty($compatibleTypes) ? $compatibleTypes : ['eSIM']; // Default to eSIM if no mapping found
    }

    /**
     * Get detailed information about a specific extension (data plan).
     */
    public function getExtensionDetails(int $extensionId): array
    {
        $extensions = $this->client->get('/get/dictionary', [
            'dict' => 'extension',
            'name' => (string) $extensionId,
        ]);

        if (empty($extensions)) {
            throw new ValidationException("Extension ID {$extensionId} not found");
        }

        return $extensions[0];
    }

    /**
     * Get all available tariffs for a specific tariff plan.
     */
    public function getTariffsForPlan(int $tariffPlanId, ?string $operatorType = 'internet'): array
    {
        $params = [
            'ID_TARIFF_PLAN' => $tariffPlanId,
        ];

        if ($operatorType !== null) {
            $params['V_OPERATOR_TYPE'] = $operatorType;
        }

        return $this->client->get('/get/tariff', $params);
    }
}
