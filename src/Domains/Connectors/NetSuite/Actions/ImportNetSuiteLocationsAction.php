<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Concerns\NormalizesImportIds;
use Kanvas\Connectors\NetSuite\Enums\ConfigurationEnum;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteLocationService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteLocationWarehouseService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses as WarehousesData;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\Users;

/**
 * Maps the NetSuite locations the user picked onto Kanvas warehouses. The default location goes
 * onto the existing default warehouse rather than a new one: products already sit there and new
 * ones are created there, so a parallel warehouse would leave them reading no location. Every
 * further location becomes its own warehouse. A location is never mapped twice — two warehouses
 * mirroring one location add its stock to the product total twice.
 */
class ImportNetSuiteLocationsAction
{
    use NormalizesImportIds;

    protected NetSuiteLocationService $locations;
    protected NetSuiteLocationWarehouseService $mapping;

    public function __construct(protected Apps $app, protected Companies $company, protected Users $user)
    {
        $this->locations = new NetSuiteLocationService($app, $company);
        $this->mapping = new NetSuiteLocationWarehouseService($app, $company);
    }

    /**
     * @param list<string> $locationIds
     * @return list<Warehouses> the default warehouse first, then one per further location
     */
    public function execute(string $defaultLocationId, array $locationIds): array
    {
        $names = array_column($this->locations->active(), 'name', 'id');
        $further = array_values(array_diff($this->uniqueIds($locationIds), [$defaultLocationId]));

        foreach ([$defaultLocationId, ...$further] as $locationId) {
            if (! isset($names[$locationId])) {
                throw new ValidationException($locationId . ' is not an active NetSuite location.');
            }
        }

        $default = $this->defaultWarehouse();
        $mapped = $this->mapping->explicitMap();
        $holder = $this->mapping->warehouseFor($defaultLocationId, $mapped);

        if ($holder !== null && $holder->getId() !== $default->getId()) {
            throw new ValidationException(
                'NetSuite location ' . $defaultLocationId . ' is already mapped to warehouse ' . $holder->name . '.'
            );
        }

        $default->set(CustomFieldEnum::NET_SUITE_LOCATION_ID->value, $defaultLocationId);
        $this->company->set(ConfigurationEnum::NET_SUITE_DEFAULT_WAREHOUSE->value, $defaultLocationId);
        $mapped[$default->getId()] = $defaultLocationId;

        $warehouses = [$default];

        foreach ($further as $locationId) {
            $warehouses[] = $this->mapping->warehouseFor($locationId, $mapped)
                ?? $this->createWarehouse($locationId, (string) $names[$locationId], $default);
        }

        return $warehouses;
    }

    /**
     * The locations chosen for a product import, mapped first if they are not yet. The default
     * warehouse keeps the location it already mirrors; on a company with none mapped, the first
     * choice goes there.
     *
     * @param list<string> $locationIds
     * @return list<Warehouses> in the order chosen
     */
    public function ensureMapped(array $locationIds): array
    {
        $locationIds = $this->uniqueIds($locationIds);

        if ($locationIds === []) {
            throw new ValidationException('Choose at least one NetSuite location.');
        }

        $defaultLocationId = $this->mapping->explicitMap()[$this->defaultWarehouse()->getId()]
            ?? Str::trimToNull((string) $this->company->get(ConfigurationEnum::NET_SUITE_DEFAULT_WAREHOUSE->value))
            ?? $locationIds[0];

        $this->execute($defaultLocationId, $locationIds);
        $mapped = $this->mapping->explicitMap();

        return array_map(
            fn (string $locationId) => $this->mapping->warehouseFor($locationId, $mapped),
            $locationIds
        );
    }

    private function defaultWarehouse(): Warehouses
    {
        return Warehouses::getDefault($this->company, $this->app)
            ?? throw new ValidationException('This company has no default warehouse. Set up its inventory first.');
    }

    private function createWarehouse(string $locationId, string $name, Warehouses $default): Warehouses
    {
        $warehouse = new CreateWarehouseAction(
            new WarehousesData(
                company: $this->company,
                app: $this->app,
                user: $this->user,
                region: $default->region,
                name: $name,
            ),
            $this->user
        )->execute();

        $warehouse->set(CustomFieldEnum::NET_SUITE_LOCATION_ID->value, $locationId);

        return $warehouse;
    }
}
