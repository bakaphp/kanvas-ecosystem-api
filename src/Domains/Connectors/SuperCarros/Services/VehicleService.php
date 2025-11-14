<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SuperCarros\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\SuperCarros\Client;
use Kanvas\Connectors\SuperCarros\DataTransferObjects\Vehicle;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Regions\Models\Regions;

class VehicleService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected Regions $region,
        protected ?UserInterface $user = null
    ) {
        $this->client = new Client($this->app, $this->company);
    }

    /**
     * Get all vehicles from a customer's inventory.
     */
    public function getCustomerVehicles(): array
    {
        $response = $this->client->getVehicleList();

        if (! isset($response['Ads']) || ! is_array($response['Ads'])) {
            throw new ValidationException('Invalid vehicle list response structure');
        }

        return [
            'total' => $response['ResultCount'] ?? 0,
            'vehicles' => $response['Ads'],
        ];
    }

    /**
     * Get detailed information for a specific vehicle.
     */
    public function getVehicleDetails(int $adId): Vehicle
    {
        $vehicleData = $this->client->getVehicleDetails($adId);

        if (! isset($vehicleData['Id'])) {
            throw new ValidationException('Invalid vehicle details response structure');
        }

        return Vehicle::from($vehicleData);
    }

    /**
     * Search vehicles by criteria.
     */
    public function searchVehicles(array $filters = []): array
    {
        $vehicles = $this->getCustomerVehicles();

        if (empty($filters)) {
            return $vehicles;
        }

        $filteredVehicles = $this->filterVehicles($vehicles['vehicles'], $filters);

        return [
            'total' => count($filteredVehicles),
            'vehicles' => $filteredVehicles,
        ];
    }

    /**
     * Get vehicles by brand.
     */
    public function getVehiclesByBrand(string $brandName): array
    {
        return $this->searchVehicles(['brand' => $brandName]);
    }

    /**
     * Get vehicles by year range.
     */
    public function getVehiclesByYearRange(int $minYear, int $maxYear): array
    {
        return $this->searchVehicles([
            'year_min' => $minYear,
            'year_max' => $maxYear,
        ]);
    }

    /**
     * Get vehicles by price range.
     */
    public function getVehiclesByPriceRange(float $minPrice, float $maxPrice): array
    {
        return $this->searchVehicles([
            'price_min' => $minPrice,
            'price_max' => $maxPrice,
        ]);
    }

    /**
     * Get vehicles by condition (new/used).
     */
    public function getVehiclesByCondition(string $condition): array
    {
        return $this->searchVehicles(['condition' => $condition]);
    }

    /**
     * Filter vehicles based on criteria.
     */
    protected function filterVehicles(array $vehicles, array $filters): array
    {
        return array_filter($vehicles, function ($vehicle) use ($filters) {
            if (! is_array($vehicle)) {
                return false;
            }

            foreach ($filters as $key => $value) {
                switch ($key) {
                    case 'brand':
                        if (! isset($vehicle['BrandName']) || stripos($vehicle['BrandName'], $value) === false) {
                            return false;
                        }

                        break;
                    case 'model':
                        if (! isset($vehicle['ModelName']) || stripos($vehicle['ModelName'], $value) === false) {
                            return false;
                        }

                        break;
                    case 'year_min':
                        if (! isset($vehicle['Year']) || (int) $vehicle['Year'] < $value) {
                            return false;
                        }

                        break;
                    case 'year_max':
                        if (! isset($vehicle['Year']) || (int) $vehicle['Year'] > $value) {
                            return false;
                        }

                        break;
                    case 'price_min':
                        if (! isset($vehicle['Price']) || $vehicle['Price'] < $value) {
                            return false;
                        }

                        break;
                    case 'price_max':
                        if (! isset($vehicle['Price']) || $vehicle['Price'] > $value) {
                            return false;
                        }

                        break;
                    case 'condition':
                        if (! isset($vehicle['Condition']) || stripos($vehicle['Condition'], $value) === false) {
                            return false;
                        }

                        break;
                    case 'fuel':
                        if (! isset($vehicle['Fuel']) || stripos($vehicle['Fuel'], $value) === false) {
                            return false;
                        }

                        break;
                }
            }

            return true;
        });
    }
}
