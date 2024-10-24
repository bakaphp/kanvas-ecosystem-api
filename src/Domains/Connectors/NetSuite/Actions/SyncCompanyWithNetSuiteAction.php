<?php

declare(strict_types=1);

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Client;
use Kanvas\Connectors\NetSuite\CustomFieldEnum;
use NetSuite\Classes\AddRequest;
use NetSuite\Classes\Customer;
use NetSuite\Classes\CustomerSearchBasic;
use NetSuite\Classes\SearchRequest;
use NetSuite\Classes\SearchStringField;
use NetSuite\Classes\UpdateRequest;
use NetSuite\NetSuiteService;

class SyncCompanyWithNetSuiteAction
{
    private NetSuiteService $service;

    public function __construct(
        protected AppInterface $app,
        protected Companies $company
    ) {
        $client = new Client($app, $company);
        $this->service = $client->getService();
    }

    public function execute(): Companies
    {
        if ($this->hasExistingNetSuiteId()) {
            return $this->updateExistingCustomer();
        }

        $existingCustomer = $this->findExistingCustomer();

        if ($existingCustomer) {
            // Update the found customer and store their ID
            $this->updateCompanyWithNetSuiteId($existingCustomer->internalId);

            return $this->updateExistingCustomer();
        }

        return $this->createNewCustomer();
    }

    private function hasExistingNetSuiteId(): bool
    {
        return ! empty($this->company->get(CustomFieldEnum::NET_SUITE_COMPANY_ID->value));
    }

    private function findExistingCustomer(): ?object
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

    /**
     * Create email search criteria for NetSuite.
     */
    private function createEmailSearchCriteria(): CustomerSearchBasic
    {
        $customerSearch = new CustomerSearchBasic();
        $customerSearch->email = new SearchStringField();
        $customerSearch->email->operator = 'is';
        $customerSearch->email->searchValue = $this->company->user->email;

        return $customerSearch;
    }

    /**
     * Create a new customer in NetSuite.
     */
    private function createNewCustomer(): Companies
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

    private function updateExistingCustomer(): Companies
    {
        $customer = new Customer();
        $customer->internalId = $this->company->get(CustomFieldEnum::NET_SUITE_COMPANY_ID->value);
        $customer->companyName = $this->company->name;
        $customer->phone = $this->company->user->phone;

        $updateRequest = new UpdateRequest();
        $updateRequest->record = $customer;

        $updateResponse = $this->service->update($updateRequest);

        if (! $updateResponse->writeResponse->status->isSuccess) {
            throw new Exception(
                'Error updating customer: ' .
                ($updateResponse->writeResponse->status->statusDetail[0]->message ?? 'Unknown error')
            );
        }

        return $this->company;
    }

    /**
     * Prepare customer data for NetSuite.
     */
    private function prepareCustomerData(): Customer
    {
        $customer = new Customer();
        $customer->companyName = $this->company->name;
        $customer->isPerson = false;
        $customer->email = $this->company->user->email;
        $customer->phone = $this->company->user->phone;

        return $customer;
    }

    private function updateCompanyWithNetSuiteId(string $netSuiteId): Companies
    {
        $this->company->set(CustomFieldEnum::NET_SUITE_COMPANY_ID->value, $netSuiteId);

        return $this->company;
    }
}
