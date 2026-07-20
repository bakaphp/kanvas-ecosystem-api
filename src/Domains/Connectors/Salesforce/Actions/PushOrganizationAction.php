<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Salesforce\Actions\Concerns\UpsertsByExternalId;
use Kanvas\Connectors\Salesforce\Client;
use Kanvas\Connectors\Salesforce\DataTransferObject\SalesforceAccount;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Organizations\Models\Organization;

class PushOrganizationAction
{
    use UpsertsByExternalId;

    public function __construct(
        protected Organization $organization,
    ) {
    }

    public function execute(): array
    {
        return DB::connection('crm')->transaction(function () {
            $organization = Organization::where('id', $this->organization->id)->lockForUpdate()->firstOrFail();
            $company = $organization->company;
            $data = SalesforceAccount::fromOrganization($organization)->toArray();

            $client = Client::getInstance($organization->app, $company);

            return $this->upsertByExternalId(
                $client,
                'Account',
                $organization,
                CustomFieldEnum::SALESFORCE_ACCOUNT_ID,
                $data,
            );
        });
    }
}
