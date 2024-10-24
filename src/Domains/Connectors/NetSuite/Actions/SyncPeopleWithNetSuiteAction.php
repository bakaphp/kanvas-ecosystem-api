<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Contracts\AppInterface;
use Exception;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Client;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Traits\IsNetSuiteCustomerTrait;
use Kanvas\Connectors\NetSuite\Traits\UseNetSuiteCustomerTrait;
use Kanvas\Guild\Customers\Models\People;
use NetSuite\Classes\AddRequest;
use NetSuite\Classes\Customer;
use NetSuite\Classes\CustomerSearchBasic;
use NetSuite\Classes\SearchRequest;
use NetSuite\Classes\SearchStringField;
use NetSuite\Classes\UpdateRequest;
use NetSuite\NetSuiteService;

class SyncPeopleWithNetSuiteAction
{
    use UseNetSuiteCustomerTrait;

    public function __construct(
        protected AppInterface $app,
        protected People $people
    ) {
        $client = new Client($app, $people->company);
        $this->service = $client->getService();
    }

    public function execute(): People
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

    protected function hasExistingNetSuiteId(): bool
    {
        return ! empty($this->people->get(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value));
    }

    /**
     * Create email search criteria for NetSuite.
     */
    protected function createEmailSearchCriteria(): CustomerSearchBasic
    {
        $customerSearch = new CustomerSearchBasic();
        $customerSearch->email = new SearchStringField();
        $customerSearch->email->operator = 'is';
        $customerSearch->email->searchValue = $this->people->getEmails()->count() > 0 ? $this->people->getEmails()->first()->email : '';

        return $customerSearch;
    }

    protected function updateExistingCustomer(): People
    {
        $customer = new Customer();
        $customer->internalId = $this->people->get(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value);
        $customer->firstName = $this->people->firstname;
        $customer->lastName = $this->people->lastname;
        $customer->phone = $this->people->getPhones()->count() > 0 ? $this->people->getPhones()->first()->phone : '';

        $updateRequest = new UpdateRequest();
        $updateRequest->record = $customer;

        $updateResponse = $this->service->update($updateRequest);

        if (! $updateResponse->writeResponse->status->isSuccess) {
            throw new Exception(
                'Error updating customer: ' .
                ($updateResponse->writeResponse->status->statusDetail[0]->message ?? 'Unknown error')
            );
        }

        return $this->people;
    }

    /**
     * Prepare customer data for NetSuite.
     */
    protected function prepareCustomerData(): Customer
    {
        $customer = new Customer();
        $customer->companyName = $this->people->name;
        $customer->isPerson = true;
        $customer->email = $this->people->user->email;
        $customer->phone = $this->people->user->phone;

        return $customer;
    }

    protected function updateCompanyWithNetSuiteId(string $netSuiteId): People
    {
        $this->people->set(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value, $netSuiteId);

        return $this->people;
    }
}
