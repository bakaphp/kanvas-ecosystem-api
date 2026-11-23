<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Support\Str;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Concerns\FindsNetSuiteVariant;
use Kanvas\Connectors\NetSuite\Concerns\ResolvesVariantWarehouse;
use Kanvas\Connectors\NetSuite\Enums\ImportEntityEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteCustomerSearchService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteLocationWarehouseService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteProductSearchService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteProductService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * The bulk import behind netsuiteImport: lists what NetSuite has and runs each record through the
 * same single-record action the rest of the connector uses, so an imported record is
 * indistinguishable from one pulled by an order, an invoice or the catalog sync. Running it again is
 * the resync: new records are created, existing products refreshed, existing customers left alone.
 */
class ImportNetSuiteDataAction
{
    use FindsNetSuiteVariant;
    use ResolvesVariantWarehouse;

    protected NetSuiteProductSearchService $productSearch;
    protected NetSuiteProductService $productService;
    protected NetSuiteCustomerSearchService $customerSearch;
    protected PullNetSuiteProductAction $pullProduct;
    protected PullNetSuiteProductPriceAction $priceRefresh;
    protected SyncNetSuiteCustomerWithOrganizationAction $syncCustomer;
    protected PullNetSuiteProductStockAction $stock;
    protected NetSuiteLocationWarehouseService $locationMapping;

    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected Users $user
    ) {
        $this->productSearch = new NetSuiteProductSearchService($app, $company);
        $this->productService = new NetSuiteProductService($app, $company);
        $this->customerSearch = new NetSuiteCustomerSearchService($app, $company);
        $this->pullProduct = new PullNetSuiteProductAction($app, $company, $user);
        $this->priceRefresh = new PullNetSuiteProductPriceAction($app, $company, $user);
        $this->syncCustomer = new SyncNetSuiteCustomerWithOrganizationAction($app, $company, $user);
        $this->stock = new PullNetSuiteProductStockAction($app, $company, $user);
        $this->locationMapping = new NetSuiteLocationWarehouseService($app, $company);
    }

    /**
     * Products are listed by item number, the key PullNetSuiteProductAction and the catalog use;
     * customers by internal id. A saved search joined to locations repeats an item once per
     * location, hence the unique.
     *
     * @param list<string> $locationIds products only: list what these locations carry
     * @return list<string>
     */
    public function listIds(ImportEntityEnum $entity, ?string $savedSearchId, array $locationIds = []): array
    {
        $ids = match ($entity) {
            ImportEntityEnum::PRODUCTS => $savedSearchId === null
                ? $this->productService->activeInventoryItemIds($locationIds)
                : array_column($this->productSearch->searchWithSavedSearch($savedSearchId), 'itemId'),
            ImportEntityEnum::CUSTOMERS => $savedSearchId === null
                ? $this->customerSearch->activeCustomerIds()
                : array_column($this->customerSearch->searchWithSavedSearch($savedSearchId), 'internalId'),
            ImportEntityEnum::STOCK => throw new InvalidArgumentException('Stock is imported per location, not per record.'),
        };

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $id) => Str::trimToNull((string) $id),
            $ids
        ))));
    }

    /**
     * One record failing is reported and skipped, never fatal: a catalog always has a few items
     * that cannot be pulled, and they must not stop the rest.
     *
     * @param list<string> $ids
     * @param list<string> $locationIds products only: the warehouses each product must be carried in
     * @return array{created: int, updated: int, skipped: int, failed: int, errors: list<array{id: string, error: string}>}
     */
    public function importRecords(ImportEntityEnum $entity, array $ids, array $locationIds = []): array
    {
        if ($entity === ImportEntityEnum::STOCK) {
            throw new InvalidArgumentException('Stock is imported per location, not per record.');
        }

        $warehouses = $this->warehousesFor($locationIds);
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

        foreach ($ids as $id) {
            try {
                $outcome = $entity === ImportEntityEnum::PRODUCTS
                    ? $this->importProduct($id, $warehouses)
                    : $this->importCustomer($id);
                $result[$outcome]++;
            } catch (Throwable $e) {
                $result['failed']++;
                $result['errors'][] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        return $result;
    }

    /**
     * Totals only: the stock action's per-location SKU lists run to the size of the catalog, and the
     * summary is stored as a company setting.
     */
    public function importStock(string $savedSearchId, bool $createMissing): array
    {
        $result = $this->stock->execute($savedSearchId, createMissing: $createMissing);

        if (isset($result['error'])) {
            throw new ValidationException($result['error']);
        }

        $locationErrors = array_filter($result['locations'] ?? [], fn (array $location) => isset($location['error']));

        return [
            ...Arr::only($result, [
                'processed',
                'created',
                'not_found_in_kanvas',
                'not_found_in_netsuite',
                'not_in_warehouse',
                'update_failed',
            ]),
            'location_errors' => array_values(array_map(
                fn (array $location) => Arr::only($location, ['warehouses_id', 'location_id', 'error']),
                $locationErrors
            )),
        ];
    }

    /**
     * A new product is pulled into the first chosen location's warehouse, where the refresh fills its
     * price and that location's quantity. Every other chosen warehouse then gets a row at the same
     * price, since NetSuite prices an item and not a location; their quantities are the stock import's.
     * A variant whose product was soft-deleted goes through the pull, which restores the product.
     *
     * @param list<Warehouses> $warehouses
     */
    private function importProduct(string $barcode, array $warehouses): string
    {
        $variant = $this->findVariantBySku($barcode);

        if ($variant === null || $variant->product === null) {
            $variant = $this->pullProduct->execute($barcode, $warehouses[0] ?? null);
            $outcome = 'created';
        } else {
            $refresh = $this->priceRefresh->execute($barcode);

            if (isset($refresh['error'])) {
                throw new ValidationException($refresh['error']);
            }

            $outcome = 'updated';
        }

        foreach ($warehouses as $warehouse) {
            $this->resolveVariantWarehouse($variant, $warehouse, createMissing: true);
        }

        return $outcome;
    }

    /**
     * @param list<string> $locationIds
     * @return list<Warehouses>
     */
    private function warehousesFor(array $locationIds): array
    {
        if ($locationIds === []) {
            return [];
        }

        $mapped = $this->locationMapping->explicitMap();

        return array_map(
            fn (string $locationId) => $this->locationMapping->warehouseFor($locationId, $mapped)
                ?? throw new ValidationException('NetSuite location ' . $locationId . ' is not mapped to a warehouse.'),
            $locationIds
        );
    }

    private function importCustomer(string $customerId): string
    {
        return $this->syncCustomer->execute($customerId)->wasRecentlyCreated ? 'created' : 'skipped';
    }
}
