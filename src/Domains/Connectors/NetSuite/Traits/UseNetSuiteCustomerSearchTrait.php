<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Traits;

use Kanvas\Connectors\NetSuite\Client;
use NetSuite\Classes\CustomerSearchBasic;
use NetSuite\Classes\SearchRequest;
use NetSuite\Classes\SearchStringField;
use NetSuite\NetSuiteService;

trait UseNetSuiteCustomerSearchTrait
{
    protected Client $client;

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

        return $this->client->executeWithRateLimit(function (NetSuiteService $service) use ($searchRequest) {
            $searchResponse = $service->search($searchRequest);

            if ($searchResponse->searchResult->status->isSuccess &&
                $searchResponse->searchResult->totalRecords > 0) {
                return $searchResponse->searchResult->recordList->record[0];
            }

            return null;
        });
    }
}
