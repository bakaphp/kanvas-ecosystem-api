<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Jobs;

use Kanvas\Connectors\Zoho\Client;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class UpdateLeadFromZohoDealWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $zohoDealId = $this->webhookRequest->payload['DealID'] ?? null;
        //$memberNumber = $this->webhookRequest->payload['MemberNumber'] ?? null;

        if ($zohoDealId === null) {
            return [
                'message' => 'Zoho Deal ID not found',
            ];
        }

        $zohoCrm = Client::getInstance($this->receiver->app, $this->receiver->company);

        $zohoDealInfo = $zohoCrm->deals->get((string) $zohoDealId)->getData();

        if (empty($zohoDealInfo)) {
            return [
                'message' => 'Zoho Deal not found',
                'zoho_data' => $zohoDealInfo,
            ];
        }

        /*   $zohoLead = $zohoCrm->leads->update(
              (string) $zohoLeadId,
              [
                  'Description' => 'Lead updated via webhook',
              ]
          ); */

        $dealDescription = $zohoDealInfo['Description'] ?? 'No Description';
        $agentNotes = $zohoDealInfo['Agent_Notes'] ?? 'No Agent Notes';
        $userWritingNotes = $zohoDealInfo['Underwriter_Notes1'] ?? 'No User Writing Notes';
        $leadId = $zohoDealInfo['ZCRM_Lead_ID'] ?? null;
        $dealStage = $zohoDealInfo['Stage'] ?? null;

        if ($leadId === null) {
            return [
                'message' => 'Zoho Deal SubID not found',
                'zoho_data' => $zohoDealInfo,
            ];
        }

        /**
         * @todo  make this dynamic to find the lead by other custom fields
         */
        $lead = Lead::getByCustomField(
            CustomFieldEnum::ZOHO_LEAD_ID->value,
            (string) $leadId,
            $this->receiver->company
        );

        if (! $lead) {
            return [
                'message' => 'Lead not found for Zoho Lead ID',
                'zoho_data' => $zohoDealInfo,
            ];
        }

        if ($lead->company->getId() !== $this->receiver->company->getId() || $lead->app->getId() !== $this->receiver->app->getId()) {
            return [
                'message' => 'Lead company does not match receiver company',
                'zoho_data' => $zohoDealInfo,
            ];
        }

        if ($lead) {
            $lead->set('agent_notes', $agentNotes);
            $lead->set('uw_notes', $userWritingNotes);
            $lead->description = $dealDescription;

            $leadStatus = LeadStatus::firstOrCreate(
                [
                    'name' => $dealStage,
                ],
                [
                    'is_default' => 0,
                ]
            );

            $lead->leads_status_id = $leadStatus->id;
            $lead->saveOrFail();
        }

        return [
            'message' => 'Zoho Lead updated from Deal webhook successfully',
            'zoho_info' => $zohoDealInfo,
            'lead_id' => $lead->id ?? null,
            'lead' => $lead?->toArray() ?? null,
        ];
    }
}
