<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\NetSuite\Client;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use NetSuite\Classes\ItemSearch;
use NetSuite\Classes\ItemSearchAdvanced;
use NetSuite\Classes\ItemSearchBasic;
use NetSuite\Classes\PricingSearchBasic;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\SearchMultiSelectField;
use NetSuite\Classes\SearchStringField;

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

        $pricingBasic = new PricingSearchBasic();
        $priceLevelField = new SearchMultiSelectField();
        $priceLevelField->operator = 'anyOf';
        $plRef = new RecordRef();
        $plRef->internalId = '1'; // e.g. "5" for “Online Price” etc.

        $priceLevelField->searchValue = [$plRef];
        $pricingBasic->priceLevel = $priceLevelField;

        $itemSearch = new ItemSearch();
        $itemSearch->basic = $searchBasic;
        // Without the location filter a row comes back for every location carrying the item.
        $itemSearch->inventoryLocationJoin = NetSuiteLocationService::joinFilter([$locationId]);
        $itemSearch->pricingJoin = $pricingBasic;

        $search->criteria = $itemSearch;

        return $this->executeAdvancedSearch($search);
    }

    /**
     * Every item's stock at one inventory location.
     *
     * The location join is what makes NetSuite return `locationQuantityAvailable` at all — a
     * saved search on its own comes back with a null quantity on every row, whatever its id.
     * This is the same shape as searchProductByItemNumber() minus the item filter, so one call
     * covers the catalog instead of one SOAP round-trip per SKU.
     *
     * Note the figure is quantity *available* (on hand minus committed), not on hand.
     */
    public function searchByLocation(
        string|int $locationId,
        string|int $savedSearchId = '574'
    ): array {
        $search = new ItemSearchAdvanced();
        $search->savedSearchId = (string) $savedSearchId;

        $itemSearch = new ItemSearch();
        $itemSearch->basic = new ItemSearchBasic();
        $itemSearch->inventoryLocationJoin = NetSuiteLocationService::joinFilter([$locationId]);

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

    protected function executeAdvancedSearch(ItemSearchAdvanced $search): array
    {
        return $this->mapSearchResultToProduct($this->client->searchAll($search));
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
