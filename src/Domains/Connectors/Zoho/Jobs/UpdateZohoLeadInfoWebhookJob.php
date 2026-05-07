<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Jobs;

use Kanvas\Connectors\Zoho\Client;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class UpdateZohoLeadInfoWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $zohoLeadId = $this->webhookRequest->payload['LeadIDCPY'] ?? null;
        //$memberNumber = $this->webhookRequest->payload['MemberNumber'] ?? null;

        if ($zohoLeadId === null) {
            return [
                'message' => 'Zoho Lead ID not found',
            ];
        }

        $zohoCrm = Client::getInstance($this->receiver->app, $this->receiver->company);

        $zohoLeadInfo = $zohoCrm->leads->get((string) $zohoLeadId)->getData();

        if (empty($zohoLeadInfo)) {
            return [
                'message' => 'Zoho Lead not found',
                'zoho_data' => $zohoLeadInfo,
            ];
        }

        $zohoLead = $zohoCrm->leads->update(
            (string) $zohoLeadId,
            [
                'Description' => 'Lead updated via webhook',
            ]
        );

        return [
            'message' => 'Zoho Lead updated successfully',
            'zoho_data' => $zohoLead,
            'zoho_info' => $zohoLeadInfo,
        ];
    }
}
