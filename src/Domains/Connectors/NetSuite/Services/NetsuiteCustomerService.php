<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Kanvas\Connectors\NetSuite\Client;
use NetSuite\Classes\Customer;
use NetSuite\Classes\GetRequest;
use NetSuite\Classes\RecordRef;
use NetSuite\NetSuiteService;

class NetSuiteCustomerService
{
    protected NetSuiteService $service;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->service = (new Client($app, $company))->getService();
    }

    public function getCustomerInfo(int|string $customerId): Customer
    {
        $customerRef = new RecordRef();
        $customerRef->internalId = $customerId;
        $customerRef->type = 'customer'; // Add the record type here

        $getRequest = new GetRequest();
        $getRequest->baseRef = $customerRef;

        $response = $this->service->get($getRequest);

        if ($response->readResponse->status->isSuccess) {
            return $response->readResponse->record;
        } else {
            throw new Exception('Error retrieving customer: ' . $response->readResponse->status->statusDetail[0]->message);
        }
    }
}
