<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Jobs;

use Kanvas\Connectors\Zoho\Actions\SyncZohoLeadAction;
use Kanvas\Connectors\Zoho\Client;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;

class SwitchZohoLeadOwnerReceiverJob extends ProcessWebhookJob
{
    public function execute(): array
    {
        $zohoLeadId = $this->webhookRequest->payload['entity_id'] ?? null;
        $leadReceiver = LeadReceiver::getByIdFromCompanyApp(
            $this->receiver->configuration['receiver_id'],
            $this->receiver->company,
            $this->receiver->app
        );

        if (! $zohoLeadId) {
            return [
                'message' => 'Zoho Lead ID not found',
            ];
        }

        if (! $leadReceiver->rotation) {
            return [
                'message' => 'Lead receiver does not have a rotation',
            ];
        }

        $syncLead = new SyncZohoLeadAction(
            $this->receiver->app,
            $this->receiver->company,
            $leadReceiver,
            $zohoLeadId
        );

        $lead = $syncLead->execute();
        $lead->disableWorkflows();
        $leadOwner = $leadReceiver->rotation->getAgent();
        $lead->leads_owner_id = $leadOwner->getId();
        $lead->saveOrFail();

        if (! $lead->owner->get(CustomFieldEnum::ZOHO_USER_OWNER_ID->value)) {
            return [
                'message' => 'Lead owner does not have a Zoho User Owner ID',
            ];
        }

        //update lead in zoho
        $zohoCrm = Client::getInstance($lead->app, $lead->company);
        $zohoData = [
            'Owner' => $lead->owner->get(CustomFieldEnum::ZOHO_USER_OWNER_ID->value),
        ];
        $zohoLead = $zohoCrm->leads->update($zohoLeadId, $zohoData);

        return [
            'message'   => 'Lead update successfully via receiver '.$leadReceiver->uuid,
            'receiver'  => $leadReceiver->getId(),
            'lead'      => $lead->getId(),
            'zoho_data' => $zohoLead,
        ];
    }
}
