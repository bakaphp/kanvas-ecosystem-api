<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Salesforce\Client;
use Kanvas\Connectors\Salesforce\DataTransferObject\SalesforceOpportunity;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Deals\Models\Deal;

class SyncDealToSalesforceAction
{
    public function __construct(
        protected AppInterface $app,
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
                new SyncOrganizationToSalesforceAction($this->app, $organization)->execute();
                $organization->refresh();
            }

            $data = SalesforceOpportunity::fromDeal($deal)->toArray();

            $client = Client::getInstance($this->app, $company);
            $externalId = $deal->get(CustomFieldEnum::SALESFORCE_OPPORTUNITY_ID->value);

            if (! $externalId || $client->find('Opportunity', (string) $externalId) === null) {
                $externalId = $client->create('Opportunity', $data);
                $deal->set(CustomFieldEnum::SALESFORCE_OPPORTUNITY_ID->value, $externalId);
            } else {
                $client->update('Opportunity', (string) $externalId, $data);
            }

            return $data + ['id' => $externalId];
        });
    }
}
