<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Salesforce\Client;
use Kanvas\Connectors\Salesforce\DataTransferObject\SalesforceContact;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;

class SyncPeopleToSalesforceAction
{
    public function __construct(
        protected AppInterface $app,
        protected People $people,
    ) {
    }

    public function execute(): array
    {
        return DB::connection('crm')->transaction(function () {
            $people = People::where('id', $this->people->id)->lockForUpdate()->firstOrFail();
            $company = $people->company;

            $organization = $people->organizations()->first();
            if ($organization !== null && ! $organization->get(CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value)) {
                new SyncOrganizationToSalesforceAction($this->app, $organization)->execute();
                $organization->refresh();
            }

            $data = SalesforceContact::fromPeople($people)->toArray();

            $client = Client::getInstance($this->app, $company);
            $externalId = $people->get(CustomFieldEnum::SALESFORCE_CONTACT_ID->value);

            if (! $externalId || $client->find('Contact', (string) $externalId) === null) {
                $externalId = $client->create('Contact', $data);
                $people->set(CustomFieldEnum::SALESFORCE_CONTACT_ID->value, $externalId);
            } else {
                $client->update('Contact', (string) $externalId, $data);
            }

            return $data + ['id' => $externalId];
        });
    }
}
