<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\NetSuite\Client;
use Kanvas\Connectors\NetSuite\Concerns\NormalizesImportIds;
use NetSuite\Classes\CustomerSearch;
use NetSuite\Classes\CustomerSearchAdvanced;
use NetSuite\Classes\CustomerSearchBasic;
use NetSuite\Classes\SearchBooleanField;

class NetSuiteCustomerSearchService
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
     * @return list<string>
     */
    public function activeCustomerIds(): array
    {
        $basic = new CustomerSearchBasic();
        $basic->isInactive = new SearchBooleanField();
        $basic->isInactive->searchValue = false;

        $search = new CustomerSearch();
        $search->basic = $basic;

        return $this->uniqueIds(array_map(
            fn (object $customer) => $customer->internalId ?? null,
            $this->client->searchAll($search)
        ));
    }

    protected function executeAdvancedSearch(CustomerSearchAdvanced $search): array
    {
        return $this->mapSearchResultToCustomer($this->client->searchAll($search));
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
