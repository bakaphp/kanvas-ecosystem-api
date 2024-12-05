<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Jobs;

use Kanvas\Connectors\Zoho\Actions\SyncZohoAgentAction;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;

class SyncZohoAgentFromReceiverJob extends ProcessWebhookJob
{
    public function execute(): array
    {
        $email = $this->webhookRequest->payload['email'] ?? null;
        $leadReceiver = LeadReceiver::getByIdFromCompanyApp(
            $this->receiver->configuration['receiver_id'],
            $this->receiver->company,
            $this->receiver->app
        );

        if (! $email) {
            return [
                'message' => 'Email not found',
            ];
        }

        $syncZohoAgent = new SyncZohoAgentAction(
            $this->receiver->app,
            $this->receiver->company,
            $email
        );
        $agent = $syncZohoAgent->execute();

        return [
            'message' => 'Agent created successfully via receiver ' . $leadReceiver->uuid,
            'receiver' => $leadReceiver->getId(),
            'agent' => $agent->getId(),
        ];
    }
}
