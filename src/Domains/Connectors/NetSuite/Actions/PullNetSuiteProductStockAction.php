<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Kanvas\Connectors\NetSuite\Services\NetSuiteLocationWarehouseService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteProductSearchService;
use Kanvas\Inventory\Variants\Actions\AddToWarehouseAction;
use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses as VariantsWarehousesDto;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

/**
 * Pulls stock from NetSuite into every Kanvas warehouse that mirrors a NetSuite location.
 *
 * One search per mapped location, not one per item: `searchByLocation()` returns the whole catalog
 * for a location in a single call, and every NetSuite call queues through a small shared
 * concurrency semaphore (`NET_SUITE_MAX_CONCURRENT_REQUESTS`, default 2). A tenant with five
 * warehouses costs five calls, not five times the catalog.
 *
 * The location join is mandatory. Without it NetSuite returns `locationQuantityAvailable` as null
 * on every row, and this action used to coerce that to `0` — writing zeroes over real stock across
 * the catalog. It now refuses to write when a search comes back with no quantities at all.
 */
class PullNetSuiteProductStockAction
{
    protected NetSuiteProductSearchService $searchService;
    protected NetSuiteLocationWarehouseService $locationService;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $mainAppCompany,
        protected UserInterface $user
    ) {
        $this->searchService = new NetSuiteProductSearchService($app, $mainAppCompany);
        $this->locationService = new NetSuiteLocationWarehouseService($app, $mainAppCompany);
    }

    public function execute(string|int $savedSearchID = 576, ?array $barcodeFilter = null, bool $createMissing = false): array
    {
        $warehouseLocations = $this->locationService->map();

        if ($warehouseLocations === []) {
            return [
                'company' => $this->mainAppCompany->getId(),
                'app' => $this->app->getId(),
                'error' => 'No warehouse is mapped to a NetSuite location. Set NET_SUITE_LOCATION_ID '
                    . 'on the warehouse, or NET_SUITE_DEFAULT_WAREHOUSE on the company.',
                'searchId' => $savedSearchID,
            ];
        }

        $locations = [];
        $totals = [
            'processed' => 0,
            'not_found_in_kanvas' => 0,
            'not_found_in_netsuite' => 0,
            'not_in_warehouse' => 0,
            'created' => 0,
            'update_failed' => 0,
        ];

        foreach ($warehouseLocations as $warehouseId => $locationId) {
            $result = $this->syncLocation(
                $warehouseId,
                $locationId,
                $savedSearchID,
                $barcodeFilter,
                $createMissing,
            );

            $locations[] = $result;

            foreach (array_keys($totals) as $key) {
                $totals[$key] += is_array($result[$key] ?? null) ? count($result[$key]) : 0;
            }
        }

        return [
            'company' => $this->mainAppCompany->getId(),
            'searchId' => $savedSearchID,
            'locations' => $locations,
            ...$totals,
        ];
    }

    /**
     * @param array<array-key, mixed>|null $barcodeFilter
     * @return array<string, mixed>
     */
    private function syncLocation(
        int $warehouseId,
        string $locationId,
        string|int $savedSearchID,
        ?array $barcodeFilter,
        bool $createMissing,
    ): array {
        $warehouse = Warehouses::getByIdFromCompanyApp($warehouseId, $this->mainAppCompany, $this->app);
        $products = $this->searchService->searchByLocation($locationId, $savedSearchID);

        $netsuiteIndex = [];
        $withoutQuantity = 0;

        foreach ($products as $product) {
            if (! is_array($product) || ! isset($product['itemId'])) {
                continue;
            }

            $netsuiteIndex[(string) $product['itemId']] = $product;

            if (! isset($product['quantityAvailable'])) {
                $withoutQuantity++;
            }
        }

        // Every row missing a quantity means the search returned no stock column at all, not that
        // the location is empty. Writing that would zero the catalog, so nothing is written.
        if ($netsuiteIndex !== [] && $withoutQuantity === count($netsuiteIndex)) {
            return [
                'warehouses_id' => $warehouseId,
                'location_id' => $locationId,
                'error' => 'Saved search ' . $savedSearchID . ' returned ' . count($netsuiteIndex)
                    . ' rows at location ' . $locationId . ' with no locationQuantityAvailable on any '
                    . 'of them. Nothing written.',
            ];
        }

        $results = [
            'warehouses_id' => $warehouseId,
            'location_id' => $locationId,
            'savedSearchTotal' => count($netsuiteIndex),
            'processed' => [],
            'not_found_in_kanvas' => [],
            'not_found_in_netsuite' => [],
            'not_in_warehouse' => [],
            'created' => [],
            'update_failed' => [],
        ];

        foreach ($barcodeFilter ?? array_keys($netsuiteIndex) as $barcode) {
            $barcode = (string) $barcode;

            if (! isset($netsuiteIndex[$barcode])) {
                $results['not_found_in_netsuite'][] = $barcode;

                continue;
            }

            $variant = Variants::fromApp($this->app)
                ->fromCompany($this->mainAppCompany)
                ->where('barcode', $barcode)
                ->first();

            if (! $variant) {
                $results['not_found_in_kanvas'][] = $barcode;

                continue;
            }

            $variantWarehouse = $this->resolveVariantWarehouse($variant, $warehouse, $createMissing);

            if (! $variantWarehouse) {
                $results['not_in_warehouse'][] = $barcode;

                continue;
            }

            if ($variantWarehouse->wasRecentlyCreated) {
                $results['created'][] = $barcode;
            }

            try {
                $variantWarehouse->quantity = (int) ($netsuiteIndex[$barcode]['quantityAvailable'] ?? 0);
                $variantWarehouse->saveOrFail();
                $results['processed'][] = $barcode;
            } catch (Exception $e) {
                $results['update_failed'][] = [
                    'barcode' => $barcode,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * The row is only created on request. A warehouse mapped to the wrong location would otherwise
     * gain a row for every SKU in the catalog on its first sync, which is not something you can
     * undo by fixing the mapping — hence opt-in rather than default.
     */
    protected function resolveVariantWarehouse(Variants $variant, Warehouses $warehouse, bool $createMissing): ?VariantsWarehouses
    {
        $variantWarehouse = VariantsWarehouses::query()
            ->where('products_variants_id', $variant->getId())
            ->where('warehouses_id', $warehouse->getId())
            ->first();

        if ($variantWarehouse !== null || ! $createMissing) {
            return $variantWarehouse;
        }

        // Price comes from wherever the variant is already stocked: a new warehouse holds the same
        // goods, and a row created at 0.00 would read as free everywhere price is surfaced. The
        // default warehouse wins so a variant stocked in several places seeds the same price twice.
        $reference = VariantsWarehouses::query()
            ->where('products_variants_id', $variant->getId())
            ->notDeleted()
            ->orderByDesc('is_default')
            ->first();

        return new AddToWarehouseAction(
            $variant,
            $warehouse,
            new VariantsWarehousesDto(
                variant: $variant,
                warehouse: $warehouse,
                quantity: 0,
                price: (float) ($reference?->price ?? 0),
                sku: (string) $variant->sku,
                status_id: $reference?->status_id,
            )
        )->execute();
    }
}
