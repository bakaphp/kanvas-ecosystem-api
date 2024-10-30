<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Traits;

use Exception;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use NetSuite\Classes\AddRequest;

trait UseNetSuiteCustomerTrait
{
    use UseNetSuiteCustomerSearchTrait;

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
