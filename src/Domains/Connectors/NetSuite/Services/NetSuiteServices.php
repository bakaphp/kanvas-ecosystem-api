<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Client;
use Kanvas\Connectors\NetSuite\DataTransferObject\NetSuite as NetSuiteDto;
use Kanvas\Connectors\NetSuite\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use NetSuite\Classes\SearchRequest;
use NetSuite\Classes\CustomerSearchBasic;
use NetSuite\Classes\SearchStringField;
use NetSuite\NetSuiteService as NetSuiteSdkService;

class NetSuiteServices
{
    private NetSuiteSdkService $service;

    public function __construct(
        protected AppInterface $app,
        protected Companies $company
    ) {
        $client = new Client($app, $company);
        $this->service = $client->getService();
    }

    /**
     * Set the shopify credentials into companies custom fields.
     */
    public static function netSuitSetup(NetSuiteDto $data): bool
    {
        $configData = [
            'account' => $data->account,
            'consumerKey' => $data->consumerKey,
            'consumerSecret' => $data->consumerSecret,
            'token' => $data->token,
            'tokenSecret' => $data->tokenSecret,
        ];

        $requiredKeys = ['account', 'consumerKey', 'consumerSecret', 'token', 'tokenSecret'];
        $missingKeys = [];

        foreach ($requiredKeys as $key) {
            if (empty($configData[$key])) {
                $missingKeys[] = $key;
            }
        }

        if (! empty($missingKeys)) {
            throw new ValidationException('NetSuite configuration is missing the following keys: ' . implode(', ', $missingKeys));
        }

        return $data->app->set(ConfigurationEnum::NET_SUITE_ACCOUNT_CONFIG->value, $configData);
    }

    public function findExistingCustomer(string $email): ?object
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

    /**
     * Create email search criteria for NetSuite.
     */
    public function createEmailSearchCriteria(string $email): CustomerSearchBasic
    {
        $customerSearch = new CustomerSearchBasic();
        $customerSearch->email = new SearchStringField();
        $customerSearch->email->operator = 'is';
        $customerSearch->email->searchValue = $email;

        return $customerSearch;
    }
}
