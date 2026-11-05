<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Salesforce\Client;
use Kanvas\Connectors\Salesforce\Services\SalesforceApiClient;

/**
 * Bulk-pulls every Account from the Salesforce org, paginating through
 * `SalesforceApiClient::queryMore()` while `done === false`. Mirrors
 * `DownloadAllShopifyProductsAction::getAllProducts()` — collects every raw record first, no
 * per-record processing here. The caller (`SalesforceBackfillImportJob`) does the upsert.
 */
class PullAllOrganizationsAction
{
    private const array BASE_FIELDS = ['Id', 'Name', 'Phone', 'NumberOfEmployees'];

    /**
     * Where an org may keep the Account's domains. Company_Domains__c is a GAGroup custom field, so
     * these are only selected when the org's describe has them — naming a missing field fails the
     * whole query.
     */
    private const array DOMAIN_FIELDS = ['Website', 'Company_URL__c', 'Company_Domains__c'];

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
    ) {
    }

    public function execute(): array
    {
        $client = Client::getInstance($this->app, $this->company);

        $response = $client->query($this->soql($client));
        $records = $response['records'] ?? [];

        while (($response['done'] ?? true) === false && ! empty($response['nextRecordsUrl'])) {
            $response = $client->queryMore($response['nextRecordsUrl']);
            $records = [...$records, ...($response['records'] ?? [])];
        }

        return $records;
    }

    private function soql(SalesforceApiClient $client): string
    {
        $available = array_column($client->describeObject('Account')['fields'] ?? [], 'name');
        $fields = [...self::BASE_FIELDS, ...array_values(array_intersect(self::DOMAIN_FIELDS, $available))];

        return 'SELECT ' . implode(', ', $fields)
            . ', (SELECT Email FROM Contacts WHERE Email != null) FROM Account';
    }
}
