<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Contracts\AppInterface;
use Exception;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Client;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Traits\UseNetSuiteCustomerTrait;
use NetSuite\Classes\Customer;
use NetSuite\Classes\UpdateRequest;

class SyncCompanyWithNetSuiteAction
{
    use UseNetSuiteCustomerTrait;

    public function __construct(
        protected AppInterface $app,
        protected Companies $company
    ) {
        $this->service = (new Client($app, $company))->getService();
    }

    public function execute(): Companies
    {
        if ($this->hasExistingNetSuiteId()) {
            return $this->updateExistingCustomer();
        }

        $existingCustomer = $this->findExistingCustomer($this->company->email ?: $this->company->user->email);

        if ($existingCustomer) {
            // Update the found customer and store their ID
            $this->updateCompanyWithNetSuiteId($existingCustomer->internalId);

            return $this->updateExistingCustomer();
        }

        return $this->createNewCustomer();
    }

    protected function hasExistingNetSuiteId(): bool
    {
        return ! empty($this->company->get(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value));
    }

    protected function updateExistingCustomer(): Companies
    {
        $customer = new Customer();
        $customer->internalId = $this->company->get(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value);
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
    protected function prepareCustomerData(): Customer
    {
        $customer = new Customer();
        $customer->companyName = $this->company->name;
        $customer->isPerson = false;
        $customer->email = $this->company->email ?? $this->company->user->email;
        $customer->phone = $this->company->phone ?? $this->company->user->phone;

        return $customer;
    }

    protected function updateCompanyWithNetSuiteId(string $netSuiteId): Companies
    {
        $this->company->set(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value, $netSuiteId);

        return $this->company;
    }
}
