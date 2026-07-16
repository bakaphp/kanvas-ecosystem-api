<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Salesforce\Client;
use Kanvas\Connectors\Salesforce\DataTransferObject\SalesforceLead;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;

class SyncLeadToSalesforceAction
{
    public function __construct(
        protected AppInterface $app,
        protected Lead $lead,
    ) {
    }

    public function execute(): array
    {
        return DB::connection('crm')->transaction(function () {
            $lead = Lead::where('id', $this->lead->id)->lockForUpdate()->firstOrFail();
            $company = $lead->company;
            $data = SalesforceLead::fromLead($lead)->toArray();

            $client = Client::getInstance($this->app, $company);
            $externalId = $lead->get(CustomFieldEnum::SALESFORCE_LEAD_ID->value);

            if (! $externalId || $client->find('Lead', (string) $externalId) === null) {
                $externalId = $client->create('Lead', $data);
                $lead->set(CustomFieldEnum::SALESFORCE_LEAD_ID->value, $externalId);
            } else {
                $client->update('Lead', (string) $externalId, $data);
            }

            return $data + ['id' => $externalId];
        });
    }
}
