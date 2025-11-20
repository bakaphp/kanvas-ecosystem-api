<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SuperCarros\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Kanvas\Connectors\SuperCarros\DataTransferObjects\Vehicle;
use Kanvas\Connectors\SuperCarros\Services\VehicleService;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Regions\Models\Regions;
use Throwable;

class SuperCarrosVehicleInventoryImportAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected UserInterface $user,
        protected Regions $region,
        protected ?Warehouses $warehouse = null,
        protected ?Channels $channel = null,
        protected bool $unpublishAllBeforeImport = false
    ) {
    }

    /**
     * Execute the vehicle inventory import.
     */
    public function execute(): array
    {
        $vehicleService = new VehicleService(
            $this->app,
            $this->company,
            $this->region,
            $this->user,
        );

        // Get warehouse
        $warehouse = $this->warehouse ?: $this->region->defaultWarehouse;
        if (! $warehouse) {
            throw new Exception('No warehouse found for the region.');
        }

        // Get channel
        $channel = $this->channel ?: Channels::getDefault($this->company);

        // Unpublish all variants from channel if requested
        if ($this->unpublishAllBeforeImport && $channel) {
            $channel->unPublishAllVariants();
        }

        $successCount = 0;
        $failedCount = 0;
        $importedProducts = [];
        $errors = [];

        try {
            // Get all vehicles for the customer
            $vehiclesResponse = $vehicleService->getCustomerVehicles();
            $vehicles = $vehiclesResponse['vehicles'] ?? [];

            if (empty($vehicles)) {
                return [
                    'success' => true,
                    'total_processed' => 0,
                    'imported' => 0,
                    'failed' => 0,
                    'products' => [],
                    'errors' => ['No vehicles found'],
                ];
            }

            foreach ($vehicles as $vehicleData) {
                try {
                    // Get detailed vehicle information
                    $vehicle = $vehicleService->getVehicleDetails($vehicleData['Id']);

                    // Map vehicle DTO to product structure
                    $mappedProductData = $this->mapVehicleToProductStructure($vehicle, $warehouse);

                    // Import the product
                    $importedProduct = (
                        new ProductImporterAction(
                            ProductImporter::from($mappedProductData),
                            $this->company,
                            $this->user,
                            $this->region,
                            $this->app,
                            true
                        )
                    )->execute();

                    // Make the product searchable
                    $importedProduct->searchable();

                    $importedProducts[] = $importedProduct;
                    $successCount++;
                } catch (Throwable $e) {
                    $failedCount++;
                    $errors[] = 'Failed to import vehicle ID ' . ($vehicleData['Id'] ?? 'unknown') . ': ' . $e->getMessage();

                    continue;
                }
            }
        } catch (Throwable $e) {
            return [
                'success' => false,
                'total_processed' => 0,
                'imported' => 0,
                'failed' => 1,
                'products' => [],
                'errors' => ['Failed to fetch vehicles: ' . $e->getMessage()],
            ];
        }

        return [
            'success' => true,
            'total_processed' => count($vehicles ?? []),
            'imported' => $successCount,
            'failed' => $failedCount,
            'products' => $importedProducts,
            'errors' => $errors,
        ];
    }

    /**
     * Map SuperCarros vehicle data to product structure.
     */
    private function mapVehicleToProductStructure(Vehicle $vehicle, Warehouses $warehouse): array
    {
        $channels = $this->channel ?: Channels::getDefault($this->company);

        $price = $vehicle->price?->amount ?? 0;
        $sku = (string) $vehicle->id;
        $name = $vehicle->fullName ?: ($vehicle->brand . ' ' . $vehicle->model . ' ' . $vehicle->year);

        // Build simple description
        $description = $this->buildVehicleDescription($vehicle);

        // Map images
        $files = [];
        if ($vehicle->images?->gallery) {
            $imageIndex = 0;
            foreach ($vehicle->images->gallery as $image) {
                $imageIndex++;
                $imageUrl = $image['PhotoUrlBig'] ?? $image['PhotoUrl'] ?? '';
                if (! empty($imageUrl)) {
                    $files[] = [
                        'url' => $imageUrl,
                        'name' => 'image_' . $imageIndex,
                        'field_name' => 'product_image',
                    ];
                }
            }
        }

        // Add main image if available
        if ($vehicle->images?->mainBig) {
            array_unshift($files, [
                'url' => $vehicle->images->mainBig,
                'name' => 'main_image',
                'field_name' => 'product_image',
            ]);
        }

        $isNew = $vehicle->condition === 'Nuevo';

        $mappedProduct = [
            'name' => $name,
            'description' => $description,
            'price' => $price,
            'discountPrice' => null,
            'slug' => Str::slug($name . '-' . $sku),
            'sku' => $sku,
            'source' => 'supercarros',
            'source_id' => $sku,
            'files' => $files,
            'quantity' => 1,
            'isPublished' => true,
            'categories' => [],
            'warehouses' => [
                [
                    'id' => $warehouse->id,
                    'price' => $price,
                    'warehouse' => $warehouse->name,
                    'quantity' => 1,
                    'sku' => $sku,
                    'is_new' => $isNew,
                    'channel' => $channels?->name ?? 'Default',
                ],
            ],
            'attributes' => $this->getVehicleAttributes($vehicle),
            'custom_fields' => [
                'supercarros_vehicle_id' => $vehicle->id,
            ],
        ];

        // Create variant
        $variant = [
            'name' => $name,
            'description' => $description,
            'slug' => Str::slug($name . '-' . $sku),
            'sku' => 'S' . $sku,
            'price' => $price,
            'discountPrice' => null,
            'quantity' => 1,
            'attributes' => $this->getVehicleAttributes($vehicle),
            'files' => $files,
            'warehouses' => [
                [
                    'id' => $warehouse->id,
                    'price' => $price,
                    'quantity' => 1,
                    'sku' => $sku,
                    'is_new' => $isNew,
                ],
            ],
            'channels' => [
                [
                    'price' => $price,
                    'discounted_price' => null,
                    'is_published' => true,
                    'warehouses_id' => $warehouse->id,
                    'channels_id' => $channels?->id ?? 0,
                ],
            ],
        ];

        $mappedProduct['variants'] = [$variant];

        return $mappedProduct;
    }

    /**
     * Build simplified vehicle description.
     */
    private function buildVehicleDescription(Vehicle $vehicle): string
    {
        $description = [];

        // Add main description if available
        if ($vehicle->description) {
            $description[] = $vehicle->description;
        }

        // Add basic specs
        $specs = [];
        if ($vehicle->year) {
            $specs[] = 'Año: ' . $vehicle->year;
        }
        if ($vehicle->transmission) {
            $specs[] = 'Transmisión: ' . $vehicle->transmission;
        }
        if ($vehicle->fuelType) {
            $specs[] = 'Combustible: ' . $vehicle->fuelType;
        }

        if (! empty($specs)) {
            $description[] = "\nEspecificaciones:\n• " . implode("\n• ", $specs);
        }

        return implode("\n", $description);
    }

    /**
     * Get essential vehicle attributes in standardized format.
     */
    private function getVehicleAttributes(Vehicle $vehicle): array
    {
        $attributes = [
            [
                'name' => 'year',
                'value' => $vehicle->year ?: '',
            ],
            [
                'name' => 'make',
                'value' => $vehicle->brand ?: '',
            ],
            [
                'name' => 'model',
                'value' => $vehicle->model ?: '',
            ],
            [
                'name' => 'condition',
                'value' => $vehicle->condition ?: '',
            ],
            [
                'name' => 'new',
                'value' => $vehicle->condition ?: '',
            ],
            [
                'name' => 'body',
                'value' => $vehicle->type ?: '',
            ],
            [
                'name' => 'accessory',
                'value' => collect($vehicle->components)->pluck('Name')->toArray(),
            ],
            [
                'name' => 'transmission',
                'value' => $vehicle->transmission ?: '',
            ],
            [
                'name' => 'fuel_type',
                'value' => $vehicle->fuelType ?: '',
            ],
            [
                'name' => 'exterior_color',
                'value' => $vehicle->colors?->exterior ?: '',
            ],
            [
                'name' => 'interior_color',
                'value' => $vehicle->colors?->interior ?: '',
            ],
            [
                'name' => 'engine_type',
                'value' => $vehicle->engine?->type ?: '',
            ],
            [
                'name' => 'engine_cylinders',
                'value' => $vehicle->engine?->cylinders ?: '',
            ],
            [
                'name' => 'door_count',
                'value' => $vehicle->specifications?->doors ?: '',
            ],
            [
                'name' => 'passengers',
                'value' => $vehicle->specifications?->passengers ?: '',
            ],
            [
                'name' => 'usage',
                'value' => $vehicle->specifications?->usage ?: '',
            ],
        ];

        // Remove attributes with empty values
        $filteredAttributes = [];
        foreach ($attributes as $attribute) {
            if (is_scalar($attribute['value'])) {
                $value = (string) $attribute['value'];
                if (! empty(trim($value))) {
                    $filteredAttributes[] = $attribute;
                }
            } elseif (is_array($attribute['value']) && ! empty($attribute['value'])) {
                $filteredAttributes[] = $attribute;
            }
        }

        return $filteredAttributes;
    }
}
