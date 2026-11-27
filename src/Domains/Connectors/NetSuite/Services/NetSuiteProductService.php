<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Kanvas\Connectors\NetSuite\Client;
use Kanvas\Connectors\NetSuite\Concerns\NormalizesImportIds;
use NetSuite\Classes\InventoryItem;
use NetSuite\Classes\ItemSearch;
use NetSuite\Classes\ItemSearchBasic;
use NetSuite\Classes\ItemSearchRow;
use NetSuite\Classes\ItemType;
use NetSuite\Classes\RecordType;
use NetSuite\Classes\SearchBooleanField;
use NetSuite\Classes\SearchEnumMultiSelectField;
use NetSuite\Classes\SearchEnumMultiSelectFieldOperator;
use NetSuite\Classes\SearchRequest;
use NetSuite\Classes\SearchStringField;
use NetSuite\NetSuiteService;

class NetSuiteProductService
{
    use NormalizesImportIds;

    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->client = new Client($app, $company);
    }

    /**
     * The item number of every active inventory item, or only of those the given locations carry.
     * Only inventory items: getProductById() reads that record type, so a kit or service item
     * listed here could never be pulled. A basic search answers one record per item even when it
     * is carried at several of the locations.
     *
     * @param list<string> $locationIds
     * @return list<string>
     */
    public function activeInventoryItemIds(array $locationIds = []): array
    {
        $basic = new ItemSearchBasic();
        $basic->isInactive = new SearchBooleanField();
        $basic->isInactive->searchValue = false;
        $basic->type = new SearchEnumMultiSelectField();
        $basic->type->operator = SearchEnumMultiSelectFieldOperator::anyOf;
        $basic->type->searchValue = [ItemType::_inventoryItem];

        $search = new ItemSearch();
        $search->basic = $basic;

        if ($locationIds !== []) {
            $search->inventoryLocationJoin = NetSuiteLocationService::joinFilter($locationIds);
        }

        return $this->uniqueIds(array_map(
            fn (object $item) => $item->itemId ?? null,
            $this->client->searchAll($search)
        ));
    }

    /**
     * Get product by its internal ID.
     */
    public function getProductById(int|string $productId): InventoryItem
    {
        return $this->client->getRecord(RecordType::inventoryItem, $productId, 'product');
    }

    public function getInventoryQuantityByLocation(InventoryItem $product, int|string $locationId): int
    {
        if (! isset($product->locationsList->locations)) {
            throw new Exception('Inventory locations not found for the specified product.');
        }

        foreach ($product->locationsList->locations as $assignment) {
            if ($assignment->location == $locationId) {
                return (int)$assignment->quantityAvailable;
            }
        }

        throw new Exception('Inventory quantity not found for the specified location.');
    }

    public function getInventoryQuantityFromSearch(ItemSearchRow $product, int|string $locationId): int
    {
        if (! isset($product->basic->inventoryLocation)) {
            throw new Exception('Inventory locations not found for the specified product.');
        }

        $inventoryIndex = null;

        foreach ($product->basic->inventoryLocation as $index => $assignment) {
            if ($assignment->searchValue->internalId == $locationId) {
                $inventoryIndex = $index;

                break;
            }
        }

        if ($inventoryIndex !== null && isset($product->basic->locationQuantityAvailable[$inventoryIndex])) {
            return (int)$product->basic->locationQuantityAvailable[$inventoryIndex]->searchValue;
        }

        throw new Exception('Inventory quantity not found for the specified location.');
    }

    public function getProductPrice(InventoryItem $product, string $priceLevel = 'MSRP Price'): float
    {
        // Check if the product has pricing details
        if (! isset($product->pricingMatrix->pricing)) {
            throw new Exception('No pricing details found for this product.');
        }

        // Loop through the pricing matrix to find the price for the specified price level and customer
        foreach ($product->pricingMatrix->pricing as $pricing) {
            if ($pricing->priceLevel->name === $priceLevel) {
                return (float) $pricing->priceList->price[0]->value ?? 0;
            }
        }

        throw new Exception('Price not found for the specified criteria.');
    }

    public function getCustomField(InventoryItem $product, string $customFieldScriptId): string
    {
        if (! isset($product->customFieldList)) {
            return '';
        }

        foreach ($product->customFieldList->customField as $customField) {
            $scriptId = $customField->scriptId ?? null;
            if ($scriptId === $customFieldScriptId) {
                return (string) $customField->value ?? '';
            }
        }

        return '';
    }

    /**
     * Search for products by item name.
     */
    public function searchProductByName(string $itemName): array
    {
        $search = new ItemSearch();
        $searchBasic = new ItemSearchBasic();

        $searchBasic->displayName = new SearchStringField();
        $searchBasic->displayName->operator = 'contains';
        $searchBasic->displayName->searchValue = $itemName;

        $search->basic = $searchBasic;

        return $this->executeProductSearch($search);
    }

    /**
     * Search for products by item number.
     */
    public function searchProductByItemNumber(string|int $itemNumber): array
    {
        $search = new ItemSearch();
        $searchBasic = new ItemSearchBasic();

        $searchBasic->itemId = new SearchStringField();
        $searchBasic->itemId->operator = 'is';
        $searchBasic->itemId->searchValue = $itemNumber;

        $search->basic = $searchBasic;

        return $this->executeProductSearch($search);
    }

    /**
     * Search for products by item number using saved search.
     *
     * @deprecated Use NetSuiteProductSearchService::searchProductByItemNumberFromSavedSearch instead
     */
    public function searchProductByItemNumberFromSearch(string|int $itemNumber): array
    {
        $searchService = new NetSuiteProductSearchService($this->app, $this->company);

        return $searchService->searchProductByItemNumberFromSavedSearch($itemNumber);
    }

    /**
     * Search for products by SKU.
     */
    public function searchProductBySKU(string $sku): array
    {
        $search = new ItemSearch();
        $searchBasic = new ItemSearchBasic();

        // Assuming SKU is stored in a custom field with scriptId 'custitem_sku'
        // Adjust the scriptId according to your NetSuite configuration
        $searchBasic->upcCode = new SearchStringField();
        $searchBasic->upcCode->operator = 'is';
        $searchBasic->upcCode->searchValue = $sku;

        $search->basic = $searchBasic;

        return $this->executeProductSearch($search);
    }

    /**
     * Execute the product search and format the results.
     */
    protected function executeProductSearch(ItemSearch $search): array
    {
        $searchRequest = new SearchRequest();
        $searchRequest->searchRecord = $search;

        return $this->client->executeWithRateLimit(function (NetSuiteService $service) use ($searchRequest) {
            $response = $service->search($searchRequest);

            if (! $response->searchResult->status->isSuccess) {
                throw new Exception('Error searching products: ' . $response->searchResult->status->statusDetail[0]->message);
            }

            $products = [];
            if (isset($response->searchResult->recordList->record)) {
                foreach ($response->searchResult->recordList->record as $record) {
                    $products[] = $record;
                }
            }

            return $products;
        });
    }

    public function pullNetsuiteProductsSku(array $barcodeList): array
    {
        $productSkus = [];

        foreach ($barcodeList as $index => $barcode) {
            $product = $this->searchProductByItemNumber($barcode);
            $product = $this->getProductById($product[0]->internalId);
            $productSkus[$barcode] = $this->getCustomField($product, 'custitem5');
            echo 'Product ' . $index . ' of ' . count($barcodeList) . ' processed';
            echo PHP_EOL;
        }

        return $productSkus;
    }
}
