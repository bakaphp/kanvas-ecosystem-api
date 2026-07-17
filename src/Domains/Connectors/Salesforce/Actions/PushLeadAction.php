<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Salesforce\Actions\Concerns\UpsertsByExternalId;
use Kanvas\Connectors\Salesforce\Client;
use Kanvas\Connectors\Salesforce\DataTransferObject\SalesforceLead;
use Kanvas\Connectors\Salesforce\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;

class PushLeadAction
{
    use UpsertsByExternalId;

    public function __construct(
        protected Lead $lead,
    ) {
    }

    public function execute(): array
    {
        return DB::connection('crm')->transaction(function () {
            $lead = Lead::where('id', $this->lead->id)->lockForUpdate()->firstOrFail();
            $company = $lead->company;
            $data = SalesforceLead::fromLead($lead)->toArray();

            $client = Client::getInstance($lead->app, $company);

            return $this->upsertByExternalId(
                $client,
                'Lead',
                $lead,
                CustomFieldEnum::SALESFORCE_LEAD_ID,
                $data,
            );
        });
    }
}
