<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Kanvas\Connectors\NetSuite\Client;
use NetSuite\Classes\CustomerSearch;
use NetSuite\Classes\CustomerSearchAdvanced;
use NetSuite\Classes\CustomerSearchBasic;
use NetSuite\Classes\SearchRequest;
use NetSuite\NetSuiteService;

class NetSuiteCustomerSearchService
{
    protected NetSuiteService $service;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->service = (new Client($app, $company))->getService();
    }

    /**
     * Search using a saved search with optional custom criteria.
     */
    public function searchWithSavedSearch(
        string|int $savedSearchId,
        ?CustomerSearchBasic $searchCriteria = null
    ): array {
        $search = new CustomerSearchAdvanced();
        $search->savedSearchId = (string) $savedSearchId;

        if ($searchCriteria !== null) {
            $customerSearch = new CustomerSearch();
            $customerSearch->basic = $searchCriteria;
            $search->criteria = $customerSearch;
        }

        return $this->executeAdvancedSearch($search);
    }

    /**
     * Execute the advanced customer search and format the results.
     */
    protected function executeAdvancedSearch(CustomerSearchAdvanced $search): array
    {
        $searchRequest = new SearchRequest();
        $searchRequest->searchRecord = $search;

        $response = $this->service->search($searchRequest);

        if (! $response->searchResult->status->isSuccess) {
            throw new Exception('Error searching customers: ' . $response->searchResult->status->statusDetail[0]->message);
        }

        $customers = [];
        if (isset($response->searchResult->searchRowList->searchRow)) {
            $customers = $this->mapSearchResultToCustomer($response->searchResult->searchRowList->searchRow);
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

                $moreResponse = $this->service->searchMoreWithId($searchMoreRequest);

                if ($moreResponse->searchResult->status->isSuccess && isset($moreResponse->searchResult->searchRowList->searchRow)) {
                    $moreCustomers = $this->mapSearchResultToCustomer($moreResponse->searchResult->searchRowList->searchRow);
                    $customers = array_merge($customers, $moreCustomers);
                }
            }
        }

        return $customers;
    }

    /**
     * Map search results to customer array format.
     */
    private function mapSearchResultToCustomer(array $searchResults): array
    {
        $customers = [];
        foreach ($searchResults as $result) {
            $customer = $result->basic;
            $customers[] = [
                'internalId' => $customer->internalId[0]->searchValue->internalId ?? null,
                'entityId' => $customer->entityId[0]->searchValue ?? null,
                'companyName' => $customer->companyName[0]->searchValue ?? null,
                'firstName' => $customer->firstName[0]->searchValue ?? null,
                'lastName' => $customer->lastName[0]->searchValue ?? null,
                'email' => $customer->email[0]->searchValue ?? null,
                'phone' => $customer->phone[0]->searchValue ?? null,
            ];
        }
        return $customers;
    }
}
