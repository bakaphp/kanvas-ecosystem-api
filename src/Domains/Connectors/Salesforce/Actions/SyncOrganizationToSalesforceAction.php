<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Salesforce\Client;
use Kanvas\Connectors\Salesforce\DataTransferObject\SalesforceAccount;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Organizations\Models\Organization;

class SyncOrganizationToSalesforceAction
{
    public function __construct(
        protected AppInterface $app,
        protected Organization $organization,
    ) {
    }

    public function execute(): array
    {
        return DB::connection('crm')->transaction(function () {
            $organization = Organization::where('id', $this->organization->id)->lockForUpdate()->firstOrFail();
            $company = $organization->company;
            $data = SalesforceAccount::fromOrganization($organization)->toArray();

            $client = Client::getInstance($this->app, $company);
            $externalId = $organization->get(CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value);

            if (! $externalId || $client->find('Account', (string) $externalId) === null) {
                $externalId = $client->create('Account', $data);
                $organization->set(CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value, $externalId);
            } else {
                $client->update('Account', (string) $externalId, $data);
            }

            return $data + ['id' => $externalId];
        });
    }
}
