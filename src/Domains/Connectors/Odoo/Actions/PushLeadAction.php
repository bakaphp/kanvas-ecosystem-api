<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Odoo\Actions\Concerns\UpsertsByExternalId;
use Kanvas\Connectors\Odoo\Client;
use Kanvas\Connectors\Odoo\DataTransferObject\OdooLead;
use Kanvas\Connectors\Odoo\Enums\CustomFieldEnum;
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
            $data = OdooLead::fromLead($lead)->toArray();

            $client = Client::getInstance($lead->app, $company);

            return $this->upsertByExternalId(
                $client,
                'crm.lead',
                $lead,
                CustomFieldEnum::ODOO_LEAD_ID,
                $data,
            );
        });
    }
}
