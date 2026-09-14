<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Jobs;

use Kanvas\Connectors\Zoho\Actions\SyncZohoLeadAction;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

#[WorkflowAction(
    name: 'Zoho Sync Lead Webhook',
    description: 'Receiver that pulls a Zoho lead into Kanvas, creating or updating it. Inbound one-way — the '
        . 'opposite direction to the Zoho lead push step.',
    integration: IntegrationsEnum::ZOHO,
)]
class SyncZohoLeadFromReceiverJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $zohoLeadId = $this->webhookRequest->payload['entity_id'] ?? null;
        $leadReceiver = LeadReceiver::getByIdFromCompanyApp(
            $this->receiver->configuration['receiver_id'],
            $this->receiver->company,
            $this->receiver->app
        );

        if ($zohoLeadId === null) {
            return [
                'message' => 'Zoho Lead ID not found',
            ];
        }

        $syncLead = new SyncZohoLeadAction(
            $this->receiver->app,
            $this->receiver->company,
            $leadReceiver,
            $zohoLeadId
        );

        $lead = $syncLead->execute();

        return [
            'message' => 'Lead created successfully via receiver ' . $leadReceiver->uuid,
            'receiver' => $leadReceiver->getId(),
            'lead' => $lead?->getId(),
        ];
    }
}
