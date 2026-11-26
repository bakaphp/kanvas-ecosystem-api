<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Importers;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\ImportNetSuiteLocationsAction;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteProductAction;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteProductPriceAction;
use Kanvas\Connectors\NetSuite\Concerns\FindsNetSuiteVariant;
use Kanvas\Connectors\NetSuite\Concerns\NormalizesImportIds;
use Kanvas\Connectors\NetSuite\Enums\ImportEntityEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteLocationService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteLocationWarehouseService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteProductSearchService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteProductService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Variants\Actions\EnsureVariantInWarehouseAction;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Integrations\Imports\DataTransferObject\ImportChoice;
use Kanvas\Workflow\Integrations\Imports\Enums\ImportOutcomeEnum;
use Kanvas\Workflow\Integrations\Imports\Importers\ChunkedImporter;
use Kanvas\Workflow\Integrations\Imports\Models\IntegrationImport;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Override;

class NetSuiteProductsImporter extends ChunkedImporter
{
    use FindsNetSuiteVariant;
    use NormalizesImportIds;

    protected NetSuiteProductSearchService $productSearch;
    protected NetSuiteProductService $productService;
    protected PullNetSuiteProductAction $pullProduct;
    protected PullNetSuiteProductPriceAction $priceRefresh;
    protected NetSuiteLocationWarehouseService $locationMapping;
    protected NetSuiteLocationService $locationService;
    protected ImportNetSuiteLocationsAction $locationImport;

    /** Read as properties by FindsNetSuiteVariant, which cannot call app()/company(). */
    protected Apps $app;
    protected Companies $company;

    /** @var array<string, list<Warehouses>> */
    private array $warehouses = [];

    public function __construct(IntegrationsCompany $integrationCompany, Users $user)
    {
        parent::__construct($integrationCompany, $user);

        $this->app = $this->app();
        $this->company = $this->company();
        $this->productSearch = new NetSuiteProductSearchService($this->app, $this->company);
        $this->productService = new NetSuiteProductService($this->app, $this->company);
        $this->pullProduct = new PullNetSuiteProductAction($this->app, $this->company, $user);
        $this->priceRefresh = new PullNetSuiteProductPriceAction($this->app, $this->company, $user);
        $this->locationMapping = new NetSuiteLocationWarehouseService($this->app, $this->company);
        $this->locationService = new NetSuiteLocationService($this->app, $this->company);
        $this->locationImport = new ImportNetSuiteLocationsAction($this->app, $this->company, $user);
    }

    /**
     * Mapping comes first: an unknown location is refused before anything is queued.
     */
    #[Override]
    public function prepare(array $options): array
    {
        $locationIds = $this->uniqueIds($options['location_ids']);

        $this->locationImport->ensureMapped($locationIds);

        return [
            ...$options,
            'location_ids' => $locationIds,
            'saved_search_id' => ImportEntityEnum::PRODUCTS->resolveSavedSearchId(
                $this->app(),
                $this->company(),
                $options['saved_search_id'] ?? null
            ),
        ];
    }

    #[Override]
    public function choices(string $option): array
    {
        if ($option !== 'location_ids') {
            return [];
        }

        return array_map(
            fn (array $location) => new ImportChoice($location['id'], $location['name'], $location['suggested']),
            $this->locationService->active()
        );
    }

    /**
     * Products are listed by item number, the key PullNetSuiteProductAction and the catalog use.
     * Inventory items only: getProductById() reads that record type, so a kit or a service item could
     * never be pulled.
     */
    #[Override]
    public function list(array $options): array
    {
        $savedSearchId = $options['saved_search_id'] ?? null;

        return $this->uniqueIds(
            $savedSearchId === null
                ? $this->productService->activeInventoryItemIds($options['location_ids'] ?? [])
                : array_column($this->productSearch->searchWithSavedSearch($savedSearchId), 'itemId')
        );
    }

    /**
     * A new product is pulled into the first chosen location's warehouse, where the refresh fills its
     * price and that location's quantity. Every other chosen warehouse then gets a row at the same
     * price, since NetSuite prices an item and not a location; their quantities are the stock import's.
     * A variant whose product was soft-deleted goes through the pull, which restores the product.
     */
    #[Override]
    public function importOne(mixed $item, array $options): ImportOutcomeEnum
    {
        $barcode = (string) $item;
        $warehouses = $this->warehousesFor($options['location_ids'] ?? []);
        $variant = $this->findVariantBySku($barcode);

        if ($variant === null || $variant->product === null) {
            $variant = $this->pullProduct->execute($barcode, $warehouses[0] ?? null);
            $outcome = ImportOutcomeEnum::CREATED;
        } else {
            $refresh = $this->priceRefresh->execute($barcode);

            if (isset($refresh['error'])) {
                throw new ValidationException($refresh['error']);
            }

            $outcome = ImportOutcomeEnum::UPDATED;
        }

        foreach ($warehouses as $warehouse) {
            new EnsureVariantInWarehouseAction($variant, $warehouse)->execute();
        }

        return $outcome;
    }

    /**
     * A product's refresh brings the quantity of the first chosen location only. The others come
     * from one stock search per location, far cheaper than a call per product and location.
     */
    #[Override]
    public function completed(IntegrationImport $run): ?array
    {
        if (count($run->options['location_ids'] ?? []) < 2) {
            return null;
        }

        return $this->startFollowUp('stock');
    }

    /**
     * @param list<string> $locationIds
     * @return list<Warehouses>
     */
    private function warehousesFor(array $locationIds): array
    {
        return $this->warehouses[implode(',', $locationIds)] ??= $this->resolveWarehouses($locationIds);
    }

    /**
     * @param list<string> $locationIds
     * @return list<Warehouses>
     */
    private function resolveWarehouses(array $locationIds): array
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
}
