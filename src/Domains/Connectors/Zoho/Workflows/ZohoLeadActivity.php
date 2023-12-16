<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Workflows;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Zoho\Client;
use Kanvas\Connectors\Zoho\DataTransferObject\ZohoLead;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Workflow\Activity;

class ZohoLeadActivity extends Activity
{
    public function execute(AppInterface $app, Lead $lead): array
    {
        $zohoLead = ZohoLead::fromLead($lead);
        $zohoData = $zohoLead->toArray();
        $company = $lead->company()->firstOrFail();

        $zohoCrm = Client::getInstance($app, $company);

        if (! $zohoLeadId = $lead->get(CustomFieldEnum::ZOHO_LEAD_ID->value)) {
            $zohoLead = $zohoCrm->leads->create($zohoData);
            $zohoLeadId = $zohoLead->getId();

            $lead->set(
                CustomFieldEnum::ZOHO_LEAD_ID->value,
                $zohoLeadId
            );
        } else {
            $zohoLead = $zohoCrm->leads->update(
                (string) $zohoLeadId,
                $zohoData
            );
        }

        return [
            'zohoLeadId' => $zohoLeadId,
            'zohoRequest' => $zohoData,
            'leadId' => $lead->getId(),
        ];
    }
}
