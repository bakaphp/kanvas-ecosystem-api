<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Salesforce\Actions\Concerns\UpsertsByExternalId;
use Kanvas\Connectors\Salesforce\Client;
use Kanvas\Connectors\Salesforce\DataTransferObject\SalesforceOpportunity;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Deals\Models\Deal;

class PushDealAction
{
    use UpsertsByExternalId;

    public function __construct(
        protected Deal $deal,
    ) {
    }

    public function execute(): array
    {
        return DB::connection('crm')->transaction(function () {
            $deal = Deal::where('id', $this->deal->id)->lockForUpdate()->firstOrFail();
            $company = $deal->company;

            $organization = $deal->organization;
            if ($organization !== null && ! $organization->get(CustomFieldEnum::SALESFORCE_ACCOUNT_ID->value)) {
                new PushOrganizationAction($organization)->execute();
                $organization->refresh();
            }

            $data = SalesforceOpportunity::fromDeal($deal)->toArray();

            $client = Client::getInstance($deal->app, $company);

            return $this->upsertByExternalId(
                $client,
                'Opportunity',
                $deal,
                CustomFieldEnum::SALESFORCE_OPPORTUNITY_ID,
                $data,
            );
        });
    }
}
