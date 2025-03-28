<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Kanvas\Connectors\NetSuite\Client;
use NetSuite\Classes\GetRequest;
use NetSuite\Classes\InventoryItem;
use NetSuite\Classes\ItemSearch;
use NetSuite\Classes\ItemSearchBasic;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\RecordType;
use NetSuite\Classes\SearchRequest;
use NetSuite\Classes\SearchStringField;
use NetSuite\NetSuiteService;

class NetSuiteProductService
{
    protected NetSuiteService $service;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->service = (new Client($app, $company))->getService();
    }

    /**
     * Get product by its internal ID.
     */
    public function getProductById(int|string $productId): InventoryItem
    {
        $productRef = new RecordRef();
        $productRef->internalId = $productId;
        $productRef->type = RecordType::inventoryItem;

        $getRequest = new GetRequest();
        $getRequest->baseRef = $productRef;

        $response = $this->service->get($getRequest);

        if ($response->readResponse->status->isSuccess) {
            return $response->readResponse->record;
        }

        throw new Exception('Error retrieving product: ' . $response->readResponse->status->statusDetail[0]->message);
    }

    public function getInventoryQuantityByLocation(InventoryItem $product, int|string $locationId): int
    {
        if (! isset($product->locationsList->locations)) {
            throw new Exception('Inventory locations not found for the specified product.');
        }

        foreach ($product->locationsList->locations as $assignment) {
            if ($assignment->location == $locationId) {
                return (int)$assignment->quantityOnHand;
            }
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

        $response = $this->service->search($searchRequest);

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
    }
}
