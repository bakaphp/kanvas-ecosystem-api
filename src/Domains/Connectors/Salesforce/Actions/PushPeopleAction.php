<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Salesforce\Actions\Concerns\UpsertsByExternalId;
use Kanvas\Connectors\Salesforce\Client;
use Kanvas\Connectors\Salesforce\DataTransferObject\SalesforceContact;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;

class PushPeopleAction
{
    use UpsertsByExternalId;

    public function __construct(
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
                new PushOrganizationAction($organization)->execute();
                $organization->refresh();
            }

            $data = SalesforceContact::fromPeople($people, $organization)->toArray();

            $client = Client::getInstance($people->app, $company);

            return $this->upsertByExternalId(
                $client,
                'Contact',
                $people,
                CustomFieldEnum::SALESFORCE_CONTACT_ID,
                $data,
            );
        });
    }
}
