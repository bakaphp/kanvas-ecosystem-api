<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Traits;

use NetSuite\Classes\CustomerSearchBasic;
use NetSuite\Classes\SearchRequest;
use NetSuite\Classes\SearchStringField;
use NetSuite\NetSuiteService;

trait UseNetSuiteCustomerSearchTrait
{
    protected NetSuiteService $service;

    protected function createEmailSearchCriteria(string $email): CustomerSearchBasic
    {
        $customerSearch = new CustomerSearchBasic();
        $customerSearch->email = new SearchStringField();
        $customerSearch->email->operator = 'is';
        $customerSearch->email->searchValue = $email;

        return $customerSearch;
    }

    protected function findExistingCustomer(string $email): ?object
    {
        $searchRequest = new SearchRequest();
        $searchRequest->searchRecord = $this->createEmailSearchCriteria($email);

        $searchResponse = $this->service->search($searchRequest);

        if ($searchResponse->searchResult->status->isSuccess &&
            $searchResponse->searchResult->totalRecords > 0) {
            return $searchResponse->searchResult->recordList->record[0];
        }

        return null;
    }
}
