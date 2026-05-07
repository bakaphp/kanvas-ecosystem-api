<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Kanvas\Connectors\NetSuite\Client;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use NetSuite\Classes\ItemSearch;
use NetSuite\Classes\ItemSearchAdvanced;
use NetSuite\Classes\ItemSearchBasic;
use NetSuite\Classes\LocationSearchBasic;
use NetSuite\Classes\PricingSearchBasic;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\SearchMultiSelectField;
use NetSuite\Classes\SearchRequest;
use NetSuite\Classes\SearchStringField;
use NetSuite\NetSuiteService;

class NetSuiteProductSearchService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->client = new Client($app, $company);
    }

    /**
     * Search for products by item number using saved search.
     */
    public function searchProductByItemNumber(
        string|int $itemNumber,
        string|int $locationId,
        string|int $savedSearchId = '574'
    ): array {
        $search = new ItemSearchAdvanced();
        $search->savedSearchId = (string) $savedSearchId;

        $searchBasic = new ItemSearchBasic();
        $searchBasic->itemId = new SearchStringField();
        $searchBasic->itemId->operator = 'is';
        $searchBasic->itemId->searchValue = (string) $itemNumber;

        // Location join. If we not filter a new row would appear for every available location
        $locationBasic = new LocationSearchBasic();
        $internalIdField = new SearchMultiSelectField();
        $internalIdField->operator = 'anyOf';
        $internalIdRef = new RecordRef();
        $internalIdRef->internalId = (string) $locationId;
        $internalIdField->searchValue = [$internalIdRef];
        $locationBasic->internalId = $internalIdField;

        $pricingBasic = new PricingSearchBasic();
        $priceLevelField = new SearchMultiSelectField();
        $priceLevelField->operator = 'anyOf';
        $plRef = new RecordRef();
        $plRef->internalId = "1"; // e.g. "5" for “Online Price” etc.

        $priceLevelField->searchValue = [$plRef];
        $pricingBasic->priceLevel = $priceLevelField;

        $itemSearch = new ItemSearch();
        $itemSearch->basic = $searchBasic;
        $itemSearch->inventoryLocationJoin = $locationBasic;
        $itemSearch->pricingJoin = $pricingBasic;

        $search->criteria = $itemSearch;

        return $this->executeAdvancedSearch($search);
    }

    /**
     * Search using a saved search with custom criteria.
     */
    public function searchWithSavedSearch(
        string|int $savedSearchId,
        ?ItemSearchBasic $searchCriteria = null
    ): array {
        $search = new ItemSearchAdvanced();
        $search->savedSearchId = (string) $savedSearchId;

        if ($searchCriteria !== null) {
            $itemSearch = new ItemSearch();
            $itemSearch->basic = $searchCriteria;
            $search->criteria = $itemSearch;
        }

        return $this->executeAdvancedSearch($search);
    }

    /**
     * Execute the advanced product search and format the results.
     */
    protected function executeAdvancedSearch(ItemSearchAdvanced $search): array
    {
        $searchRequest = new SearchRequest();
        $searchRequest->searchRecord = $search;

        $response = $this->client->executeWithRateLimit(function (NetSuiteService $service) use ($searchRequest) {
            return $service->search($searchRequest);
        });

        if (! $response->searchResult->status->isSuccess) {
            throw new Exception('Error searching products: ' . $response->searchResult->status->statusDetail[0]->message);
        }

        $products = [];
        if (isset($response->searchResult->searchRowList->searchRow)) {
            $products = $this->mapSearchResultToProduct($response->searchResult->searchRowList->searchRow);
        }

        // Handle pagination - fetch remaining pages if they exist
        $searchId = $response->searchResult->searchId ?? null;
        $totalPages = $response->searchResult->totalPages ?? 1;
        $currentPage = $response->searchResult->pageIndex ?? 1;

        if ($searchId && $totalPages > 1) {
            for ($pageIndex = $currentPage + 1; $pageIndex <= $totalPages; $pageIndex++) {
                $searchMoreRequest = new \NetSuite\Classes\SearchMoreWithIdRequest();
                $searchMoreRequest->searchId = $searchId;
                $searchMoreRequest->pageIndex = $pageIndex;

                $moreResponse = $this->client->executeWithRateLimit(function (NetSuiteService $service) use ($searchMoreRequest) {
                    return $service->searchMoreWithId($searchMoreRequest);
                });

                if ($moreResponse->searchResult->status->isSuccess && isset($moreResponse->searchResult->searchRowList->searchRow)) {
                    $moreProducts = $this->mapSearchResultToProduct($moreResponse->searchResult->searchRowList->searchRow);
                    $products = array_merge($products, $moreProducts);
                }
            }
        }

        return $products;
    }

    /**
     * Map search results to product array format.
     */
    private function mapSearchResultToProduct(array $searchResults): array
    {
        $products = [];
        foreach ($searchResults as $result) {
            $product = $result->basic;
            $products[] = [
                'internalId' => $product->internalId[0]->searchValue->internalId ?? null,
                'inventoryLocationId' => $product->inventoryLocationJoin[0]->searchValue->internalId ?? null,
                'itemId' => $product->itemId[0]->searchValue ?? null,
                'displayName' => $product->displayName[0]->searchValue ?? null,
                'quantityAvailable' => $product->locationQuantityAvailable[0]->searchValue ?? null,
                'basePrice' => $product->basePrice[0]->searchValue ?? null,
                'minimumQuantity' => $product->minimumQuantity[0]->searchValue ?? null,
                'mapPrice' => $this->getCustomFieldFromSearchRow($result, CustomFieldEnum::NET_SUITE_MAP_PRICE_CUSTOM_FIELD->value),
                'colorCode' => $this->getCustomFieldFromSearchRow($result, CustomFieldEnum::NET_SUITE_COLOR_CODE_CUSTOM_FIELD->value),
                'moq' => $this->getCustomFieldFromSearchRow($result, CustomFieldEnum::NET_SUITE_MOQ_CUSTOM_FIELD->value),
            ];
        }
        return $products;
    }

    /**
     * Get custom field value from search row result.
     */
    private function getCustomFieldFromSearchRow(object $searchRow, string $customFieldScriptId): string
    {
        if (! isset($searchRow->basic->customFieldList)) {
            return '';
        }

        foreach ($searchRow->basic->customFieldList->customField as $customField) {
            $scriptId = $customField->scriptId ?? null;
            if ($scriptId === $customFieldScriptId) {
                return (string) ($customField->searchValue ?? '');
            }
        }

        return '';
    }
}
