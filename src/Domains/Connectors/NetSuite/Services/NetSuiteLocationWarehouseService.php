<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Connectors\NetSuite\Enums\ConfigurationEnum;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

/**
 * Which Kanvas warehouse mirrors which NetSuite inventory location.
 *
 * The mapping lives on the **warehouse**, not on the variant-warehouse pivot. The pivot is where
 * `SyncNetSuiteProductsAction` originally read it, which meant expressing one fact — "this
 * warehouse is location 7" — once per variant: hundreds of custom-field rows, no single place to
 * read or change it. Pivot values are still honoured so existing data keeps working.
 *
 * Deliberately says nothing about how many warehouses a tenant has. One is the common case and
 * keeps working through the company-level default; two or ten need no code change, only a
 * `NET_SUITE_LOCATION_ID` on each warehouse.
 */
class NetSuiteLocationWarehouseService
{
    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
    ) {
    }

    /**
     * Warehouse id => NetSuite location id, for every warehouse that maps to one.
     *
     * @return array<int, string>
     */
    public function map(): array
    {
        $warehouses = $this->warehouses();
        $map = $this->explicitMap($warehouses);

        return $map !== [] ? $map : $this->legacySingleWarehouseMap($warehouses);
    }

    /**
     * Only the warehouses that carry a NET_SUITE_LOCATION_ID of their own, without the company
     * default fallback.
     *
     * @param iterable<int, Warehouses>|null $warehouses
     * @return array<int, string>
     */
    public function explicitMap(?iterable $warehouses = null): array
    {
        $map = [];

        foreach ($warehouses ?? $this->warehouses() as $warehouse) {
            $locationId = $warehouse->get(CustomFieldEnum::NET_SUITE_LOCATION_ID->value);

            if ($locationId !== null && $locationId !== '') {
                $map[(int) $warehouse->getId()] = (string) $locationId;
            }
        }

        return $map;
    }

    /**
     * @param array<int, string>|null $explicitMap a map already read, to avoid reading it per location
     */
    public function warehouseFor(string $locationId, ?array $explicitMap = null): ?Warehouses
    {
        $warehouseId = array_search($locationId, $explicitMap ?? $this->explicitMap(), true);

        return $warehouseId === false
            ? null
            : Warehouses::getByIdFromCompanyApp($warehouseId, $this->company, $this->app);
    }

    /**
     * Products and stock are read per location: with nothing mapped, every product would be created
     * at price 0 and counted as failed, and stock would have nowhere to go.
     */
    public function assertMapped(): void
    {
        if ($this->map() === []) {
            throw new ValidationException(
                'No warehouse of this company is mapped to a NetSuite location. Import the NetSuite locations first.'
            );
        }
    }

    /**
     * @return Collection<int, Warehouses>
     */
    private function warehouses(): Collection
    {
        return Warehouses::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->get();
    }

    /**
     * Nothing mapped explicitly — behave exactly as this connector did before warehouses could
     * carry a location: the company's default NetSuite location, written to the default
     * warehouse. Applying that fallback to *every* warehouse would quietly point a second
     * warehouse at the first one's location, which is the failure this whole change exists to
     * avoid.
     *
     * @param iterable<int, Warehouses> $warehouses
     * @return array<int, string>
     */
    private function legacySingleWarehouseMap(iterable $warehouses): array
    {
        $locationId = $this->company->get(ConfigurationEnum::NET_SUITE_DEFAULT_WAREHOUSE->value);

        if ($locationId === null || $locationId === '') {
            return [];
        }

        $fallback = null;

        foreach ($warehouses as $warehouse) {
            $fallback ??= $warehouse;

            if ((bool) $warehouse->is_default) {
                $fallback = $warehouse;

                break;
            }
        }

        return $fallback !== null ? [(int) $fallback->getId() => (string) $locationId] : [];
    }
}
