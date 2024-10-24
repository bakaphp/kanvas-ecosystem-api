<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Traits;

use Exception;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use NetSuite\Classes\AddRequest;
use NetSuite\Classes\CustomerSearchBasic;
use NetSuite\Classes\SearchRequest;
use NetSuite\NetSuiteService;

trait UseNetSuiteCustomerTrait
{
    protected NetSuiteService $service;

    abstract protected function createEmailSearchCriteria(): CustomerSearchBasic;

    protected function findExistingCustomer(): ?object
    {
        $searchRequest = new SearchRequest();
        $searchRequest->searchRecord = $this->createEmailSearchCriteria();

        $searchResponse = $this->service->search($searchRequest);

        if ($searchResponse->searchResult->status->isSuccess &&
            $searchResponse->searchResult->totalRecords > 0) {
            return $searchResponse->searchResult->recordList->record[0];
        }

        return null;
    }

    protected function createNewCustomer(): People|Companies
    {
        $customer = $this->prepareCustomerData();

        $addRequest = new AddRequest();
        $addRequest->record = $customer;

        $addResponse = $this->service->add($addRequest);

        if (! $addResponse->writeResponse->status->isSuccess) {
            throw new Exception(
                'Error creating customer: ' .
                ($addResponse->writeResponse->status->statusDetail[0]->message ?? 'Unknown error')
            );
        }

        return $this->updateCompanyWithNetSuiteId($addResponse->writeResponse->baseRef->internalId);
    }
}
