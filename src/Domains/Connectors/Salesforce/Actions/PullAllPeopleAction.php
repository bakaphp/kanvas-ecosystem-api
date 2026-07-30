<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Salesforce\Client;

/**
 * Bulk-pulls every Contact from the Salesforce org, paginating through
 * `SalesforceApiClient::queryMore()` while `done === false`. Mirrors
 * `DownloadAllShopifyProductsAction::getAllProducts()` — collects every raw record first, no
 * per-record processing here. The caller (`SalesforceBackfillImportJob`) does the upsert.
 */
class PullAllPeopleAction
{
    private const string SOQL = 'SELECT Id, FirstName, LastName, Email, Phone, AccountId FROM Contact';

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
    ) {
    }

    public function execute(): array
    {
        $client = Client::getInstance($this->app, $this->company);

        $response = $client->query(self::SOQL);
        $records = $response['records'] ?? [];

        while (($response['done'] ?? true) === false && ! empty($response['nextRecordsUrl'])) {
            $response = $client->queryMore($response['nextRecordsUrl']);
            $records = [...$records, ...($response['records'] ?? [])];
        }

        return $records;
    }
}
